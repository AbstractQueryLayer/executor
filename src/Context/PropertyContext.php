<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Context;

use IfCastle\AQL\Dsl\Node\NodeHelper;
use IfCastle\AQL\Dsl\Node\NodeInterface;
use IfCastle\AQL\Dsl\Node\NullNode;
use IfCastle\AQL\Dsl\Sql\Column\ColumnInterface;
use IfCastle\AQL\Dsl\Sql\Constant\ConstantInterface;
use IfCastle\AQL\Dsl\Sql\Constant\Variable;
use IfCastle\AQL\Dsl\Sql\Query\Expression\AssignmentListInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Operation\LROperationInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\ValueListInterface;
use IfCastle\AQL\Dsl\Sql\Query\QueryInterface;
use IfCastle\AQL\Dsl\Sql\Query\SubqueryInterface;
use IfCastle\AQL\Dsl\Sql\RawSql;
use IfCastle\AQL\Dsl\Sql\Tuple\TupleColumn;
use IfCastle\AQL\Dsl\Sql\Tuple\TupleColumnInterface;
use IfCastle\AQL\Dsl\Sql\Tuple\TupleInterface;
use IfCastle\AQL\Entity\EntityInterface;
use IfCastle\AQL\Entity\Property\PropertyInterface;
use IfCastle\AQL\Executor\Exceptions\QueryException;
use IfCastle\AQL\Executor\Plan\ColumnUnserializeHandler;
use IfCastle\Exceptions\UnexpectedValueType;

class PropertyContext extends NodeContext implements PropertyContextInterface
{
    /**
     * @inheritDoc
     */
    public function __construct(ColumnInterface $column, protected EntityInterface $ownerEntity, NodeContextInterface $context)
    {
        // Build self and inherit context name
        parent::__construct($column, $context->getContextName(), parent: $context);
        $context->addToInherited($this);
    }

    /**
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function getColumn(): ColumnInterface
    {
        $currentNode                = $this->getCurrentNode();

        if ($currentNode instanceof ColumnInterface) {
            return $currentNode;
        }

        throw new UnexpectedValueType('$this->getCurrentNode', $currentNode, ColumnInterface::class);
    }

    #[\Override]
    public function getTupleColumn(): ?TupleColumnInterface
    {
        $tupleColumn               = $this->getPropertyExpression();

        if ($tupleColumn instanceof TupleColumnInterface) {
            return $tupleColumn;
        }

        return null;
    }

    /**
     * Returns current expression for this property.
     */
    #[\Override]
    public function getPropertyExpression(): ?NodeInterface
    {
        return $this->getCurrentNode()->getParentNode();
    }

    /**
     * The method replaces the property-reference to the $node-expression.
     *
     *
     * @return  $this
     */
    #[\Override]
    public function substituteColumn(NodeInterface $node, bool $ifColumn = true): static
    {
        $column                     = $this->getCurrentNode();

        if ($ifColumn && $column instanceof ColumnInterface === false) {
            return $this;
        }

        $expression                 = $this->getPropertyExpression();

        if ($expression instanceof TupleColumnInterface) {
            $expression->setAliasIfUndefined($column->getColumnName());
        }

        $column->setSubstitution($node);

        return $this;
    }


    #[\Override]
    public function substituteColumnToNull(bool $ifColumn = true): static
    {
        return $this->substituteColumn(new NullNode(), $ifColumn);
    }

    /**
     * The method returns the right side expression if it is a constant.
     * If it is not, the method returns NULL or an exception if a parameter $isThrow is TRUE.
     *
     *
     *
     * @throws  QueryException|UnexpectedValueType
     */
    #[\Override]
    public function getRightConstant(bool $isThrow = true): ?ConstantInterface
    {
        $expression                 = $this->getPropertyExpression();

        if ($expression instanceof LROperationInterface && $expression->getRightNode() instanceof ConstantInterface) {
            return $expression->getRightNode();
        }

        if ($isThrow) {
            throw new QueryException([
                'template'          => 'The column {entity}.{column} was expecting a constant expression on the right side, but has {expression}',
                'column'            => $this->getColumn()->getColumnName(),
                'entity'            => $this->ownerEntity->getEntityName(),
                'expression'        => $expression,
            ]);
        }

        return null;
    }

    /**
     * Returns the value of the right side
     * The method returns the value of the constant right expression, if it exists.
     *
     *
     * @throws  QueryException|UnexpectedValueType
     */
    #[\Override]
    public function getRightValue(bool $isThrow = true): mixed
    {
        return $this->getRightConstant($isThrow)->getConstantValue();
    }

    /**
     * The method replaces the right side of the $node expression, if it exists.
     *
     * @return  $this
     * @throws  QueryException|UnexpectedValueType
     */
    #[\Override]
    public function substituteRightSide(NodeInterface $node): static
    {
        $this->getRightConstant()->setSubstitution($node);

        return $this;
    }

    /**
     * The method processes the right side expression through a callback function.
     *
     * @return  $this
     *
     * @throws QueryException
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function handleRightValue(callable $valueHandler): static
    {
        $constant                   = $this->getRightConstant();
        $constant->setSubstitution(new Variable($valueHandler($constant)));

        return $this;
    }

    #[\Override]
    public function findAssignValues(): array
    {
        if (false === NodeHelper::inAssign($this->currentNode)) {
            return [];
        }

        $expression                 = $this->getCurrentSqlQuery()->getAssigmentList();

        if ($expression instanceof AssignmentListInterface && $expression->isListNotEmpty()) {
            $value                  = $this->getRightConstant(isThrow: false);

            return $value !== null ? [$value] : [];
        }

        $expression                 = $this->getCurrentSqlQuery()->getValueList();

        if ($expression instanceof ValueListInterface && $expression->isListNotEmpty()) {
            return $expression->findValues($this->getColumn());
        }

        return [];
    }

    #[\Override]
    public function handleAssignValues(callable $handler): void
    {
        foreach ($this->findAssignValues() as $value) {
            $handler($value->resolveSubstitution());
        }
    }

    #[\Override]
    public function getOwnerEntity(): EntityInterface
    {
        return $this->ownerEntity;
    }

    #[\Override]
    public function getSubjectAlias(): string
    {
        return $this->findAlias($this->ownerEntity->getEntityName());
    }

    /**
     *
     * @return  $this
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function substituteWithSql(string $sql): static
    {
        return $this->substituteColumn(new RawSql($sql), true);
    }

    #[\Override]
    public function getTuple(): TupleInterface
    {
        $query                  = $this->getCurrentQuery();

        if ($query instanceof QueryInterface === false) {
            throw new QueryException([
                'template'      => 'The hidden results can be used only for QueryI, but current query is {class}',
                'class'         => $query,
            ]);
        }

        $tuple                  = $query->getTuple();

        if ($tuple === null) {
            throw new QueryException([
                'template'      => 'The hidden results can be used only for SELECT-like query with Tuple node {class} ($query->getTuple() returns null)',
                'class'         => $query,
            ]);
        }

        return $tuple;
    }

    /**
     * Adds a hidden column to the query result.
     *
     * @throws
     */
    #[\Override]
    public function addHiddenColumn(NodeInterface $column): TupleColumnInterface
    {
        $tupleColumn            = $column instanceof TupleColumnInterface ? $column : new TupleColumn($column, $this->getTuple()->generateAliasForHiddenColumn());

        $tupleColumn->transformNode($this);
        $this->getTuple()->addHiddenColumn($tupleColumn);

        return $tupleColumn;
    }

    /**
     *
     * @throws QueryException
     *
     */
    #[\Override]
    public function resolveHiddenColumn(ColumnInterface $column): TupleColumnInterface
    {
        $tupleColumn            = $this->getTuple()->resolveHiddenColumn($column);

        $this->transform($tupleColumn);

        return $tupleColumn;
    }

    #[\Override]
    public function addUnSerializedColumn(TupleColumnInterface $tupleColumn, PropertyInterface $property): static
    {
        // The rows of INSERT … SELECT go into the table, not into a result: there is nothing to unserialize.
        $query                  = $this->getCurrentQuery();

        if ($query instanceof SubqueryInterface && $query->isFromSelect() && $this->getBasicQuery()->isInsert()) {
            return $this;
        }

        $this->getQueryCommand()->getResultProcessingPlan()
            ->addRowModifier(new ColumnUnserializeHandler($tupleColumn, $property));

        return $this;
    }

    #[\Override]
    public function dispose(): void
    {
        parent::dispose();
        unset($this->ownerEntity);
    }
}
