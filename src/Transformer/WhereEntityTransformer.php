<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Transformer;

use IfCastle\AQL\Dsl\Node\Exceptions\NodeException;
use IfCastle\AQL\Dsl\Node\NodeHelper;
use IfCastle\AQL\Dsl\Node\NodeInterface;
use IfCastle\AQL\Dsl\Parser\Exceptions\ParseException;
use IfCastle\AQL\Dsl\Sql\Column\Column;
use IfCastle\AQL\Dsl\Sql\Conditions\Conditions;
use IfCastle\AQL\Dsl\Sql\Conditions\ConditionsInterface;
use IfCastle\AQL\Dsl\Sql\Query\Exceptions\TransformationException;
use IfCastle\AQL\Dsl\Sql\Query\Expression\ColumnList;
use IfCastle\AQL\Dsl\Sql\Query\Expression\From;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Operation\Exists;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Operation\LROperation;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Operation\OperationInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Subject;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Where;
use IfCastle\AQL\Dsl\Sql\Query\Expression\WhereEntity;
use IfCastle\AQL\Dsl\Sql\Query\Subquery;
use IfCastle\AQL\Dsl\Sql\Tuple\Tuple;
use IfCastle\AQL\Entity\Relation\BuildingRequiredRelationInterface;
use IfCastle\AQL\Entity\Relation\DirectRelationInterface;
use IfCastle\AQL\Entity\Relation\IndirectRelationInterface;
use IfCastle\AQL\Executor\Context\NodeContextInterface;
use IfCastle\Exceptions\RequiredValueEmpty;

class WhereEntityTransformer
{
    /**
     * @param NodeInterface[]      $nodes
     *
     */
    public static function moveOperationsToConditions(WhereEntity $whereEntity, array $nodes): void
    {
        $conditions                 = $whereEntity->conditions();
        $entityName                 = $whereEntity->getEntityName();

        foreach ($nodes as $node) {
            if ($node->isTransformed() || false === ($node instanceof OperationInterface || $node instanceof ConditionsInterface)) {
                continue;
            }

            if (NodeHelper::findColumnByEntity($node, $entityName) !== null) {
                $conditions->add(clone $node);
                $node->substituteToNullNode();
            }
        }
    }

    public function __invoke(WhereEntity $whereEntity, NodeContextInterface $context): void
    {
        // 1. Define relations
        $mainEntity                 = $context->getCurrentEntity();
        $thisEntity                 = $context->getEntity($whereEntity->getEntityName());

        $relation                   = $thisEntity->findRelation($mainEntity->getEntityName());
        $isNeedReverse              = false;

        if ($relation === null) {
            $relation               = $mainEntity->getRelation($thisEntity->getEntityName());
            $isNeedReverse          = true;
        }

        $relation                   = clone $relation;

        if ($relation instanceof BuildingRequiredRelationInterface) {
            $relation->buildRelations($context->newNodeContext($whereEntity));
        }

        if ($isNeedReverse) {
            $relation               = $relation->reverseRelation();
        }

        if ($relation instanceof IndirectRelationInterface) {
            $this->handleIndirectRelation($relation, $whereEntity);
        } elseif ($relation instanceof DirectRelationInterface) {
            $this->handleDirectRelation($relation, $whereEntity);
        } else {
            throw new NodeException($relation, 'Unknown type of relation object. Expected DirectRelationI or IndirectRelationI');
        }
    }

    /**
     * @throws RequiredValueEmpty
     * @throws ParseException
     * @throws NodeException
     */
    protected function handleDirectRelation(DirectRelationInterface $relation, WhereEntity $whereEntity): void
    {
        if ($whereEntity->isExclude()) {
            $this->excludeByNotExists($relation, $whereEntity);
            return;
        }

        // Define left and right keys for build Subquery
        $leftKey                    = $relation->getLeftKey();
        $foreignKey                 = $relation->getRightKey();
        $conditions                 = $whereEntity->getConditions();

        // Build Subquery for WHERE expression
        // mainEntity.foreignKey IN (SELECT thisEntity.leftKey FROM leftKey)
        $subquery                   = new Subquery(
            new From(new Subject($whereEntity->getEntityName())),
            new Tuple(...$leftKey->getKeyColumns()),
            $conditions !== null ? (new Where())->add($conditions) : null
        );

        // Build IN expression and Substitute self
        // $leftKey IN (Subquery)
        $whereEntity->setSubstitution(new LROperation(
            $foreignKey->isKeySimple() ? new Column($foreignKey->getKeyColumns()[0]) : new ColumnList(...$foreignKey->getKeyColumns()),
            'IN',
            $subquery
        )
        );
    }

    /**
     * NOT EXISTS (SELECT … FROM thisEntity WHERE thisEntity.leftKey = mainEntity.foreignKey AND conditions).
     * NOT IN would return no row when the subquery yields a NULL key, and drop main rows whose key is NULL.
     *
     * @throws RequiredValueEmpty
     * @throws ParseException
     */
    protected function excludeByNotExists(DirectRelationInterface $relation, WhereEntity $whereEntity): void
    {
        // Reversed, the relation's left side is the outer entity and its predicate marks those columns foreign.
        $where                      = (new Where())->add($relation->reverseRelation()->generateConditions());
        $conditions                 = $whereEntity->getConditions();

        if ($conditions !== null) {
            $where->add($conditions);
        }

        $subquery                   = new Subquery(
            new From(new Subject($whereEntity->getEntityName())),
            new Tuple(...$relation->getLeftKey()->getKeyColumns()),
            $where
        );

        $whereEntity->setSubstitution(new Exists($subquery, true));
    }

    /**
     * @throws TransformationException
     */
    protected function handleIndirectRelation(IndirectRelationInterface $relation, WhereEntity $whereEntity): void
    {
        if ($whereEntity->isForbidIndirectRelation()) {
            throw new TransformationException('Use of nested relations is prohibited during dereferencing of Indirect Relation');
        }

        $whereEntity->setSubstitution($this->convertIndirectRelation($relation, $whereEntity));
    }

    protected function convertIndirectRelation(IndirectRelationInterface $relation, WhereEntity $whereEntity, bool $forResult = false): NodeInterface
    {
        $entitiesPath               = $relation->getEntitiesPath();
        $conditions                 = $relation->getAdditionalConditions();
        $entityConditions           = $whereEntity->getConditions();

        if (\count($entitiesPath) < 3) {
            throw new TransformationException('Entities Path must contain at least three elements');
        }

        if ($forResult) {
            $entitiesPath           = \array_reverse($entitiesPath);
            //
            // We must remove the "right side" of the relationship, as it will be taken into account in
            // handleNestedRelations()
            //
            \array_shift($entitiesPath);
        }

        $toEntityName               = $entitiesPath[0];

        \array_shift($entitiesPath);

        $isExclude                  = $whereEntity->isExclude();
        $lastIndex                  = \array_key_last($entitiesPath);
        $prevWhereEntity            = null;
        $whereEntity                = null;

        //
        // Notice!
        // The process of generating an expression is upside down from right to left.
        // $whereEntity->setConditions((new Conditions())->add($prevWhereEntity));
        //
        foreach ($entitiesPath as $index => $fromEntityName) {

            // Only the outermost link negates: a book without such an author has no link row leading to one.
            $whereEntity            = WhereEntity::newNested($toEntityName, null, $isExclude && $index === $lastIndex);

            if ($prevWhereEntity !== null) {
                $whereEntity->setConditions((new Conditions())->add($prevWhereEntity));
            } else {

                if ($entityConditions !== null) {
                    if ($conditions !== null) {
                        $conditions->add($entityConditions);
                    } else {
                        $conditions = $entityConditions;
                    }
                }

                if ($conditions !== null) {
                    $whereEntity->setConditions($conditions);
                }
            }

            $toEntityName           = $fromEntityName;
            $prevWhereEntity        = $whereEntity;
        }

        return $whereEntity;
    }
}
