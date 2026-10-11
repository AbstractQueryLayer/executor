<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Transformer\Sql;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Dsl\Node\NodeInterface;
use IfCastle\AQL\Dsl\Node\NodeRecursiveIteratorBySubject;
use IfCastle\AQL\Dsl\Node\NodeTransformerIterator;
use IfCastle\AQL\Dsl\Node\RecursiveIteratorByNodeIterator;
use IfCastle\AQL\Dsl\Relation\RelationInterface;
use IfCastle\AQL\Dsl\Sql\Column\ColumnInterface;
use IfCastle\AQL\Dsl\Sql\Constant\ConstantInterface;
use IfCastle\AQL\Dsl\Sql\FunctionReference\FunctionReferenceInterface;
use IfCastle\AQL\Dsl\Sql\Query\Exceptions\TransformationException;
use IfCastle\AQL\Dsl\Sql\Query\Expression\JoinInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\SubjectInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Using;
use IfCastle\AQL\Dsl\Sql\Query\Expression\WhereEntity;
use IfCastle\AQL\Dsl\Sql\Query\QueryInterface;
use IfCastle\AQL\Dsl\Sql\Query\SelectInterface;
use IfCastle\AQL\Dsl\Sql\Query\SubqueryInterface;
use IfCastle\AQL\Dsl\Sql\Query\WithInterface;
use IfCastle\AQL\Entity\DerivedEntity\DerivedEntity;
use IfCastle\AQL\Executor\Context\NodeContextInterface;
use IfCastle\AQL\Executor\FunctionHandlerInterface;
use IfCastle\AQL\Executor\QueryHandlerInterface;
use IfCastle\AQL\Executor\QueryPostHandlerInterface;
use IfCastle\AQL\Executor\SubjectHandlerInterface;
use IfCastle\AQL\Executor\Transformer\WhereEntityTransformer;

class SqlTransformer
{
    /**
     * @throws TransformationException
     */
    public function __invoke(QueryInterface $query, NodeContextInterface $context): void
    {
        if ($context->getCurrentNode() !== $query) {
            $context                = $this->newQueryContext($query, $context);
        }

        $this->defineFactories($context);

        // A SELECT without FROM has no subject: there is no main entity and no join to normalize.
        $hasFrom                    = $query->getFrom() !== null;

        if ($hasFrom) {
            $this->normalizeMainEntity($query, $context);
        }

        $queryHandler               = $context->getQueryHandler();

        if ($queryHandler instanceof QueryHandlerInterface) {
            $queryHandler->handleQuery($query, $context);
        }

        if ($query->getSubstitution() !== null) {
            return;
        }

        if ($hasFrom) {
            $this->transformSubjects($query, $context);
        }

        if ($query->getSubstitution() !== null) {
            return;
        }

        //
        // Normalize the other nodes
        //
        $this->transformNodes($query, $context);

        if ($query->getSubstitution() !== null) {
            return;
        }

        //
        // Re-normalization of the node, after all transformations. Since other nodes are able to create additional JOINs,
        // we use additional normalization after all other nodes have been processed.
        //
        if ($hasFrom) {
            $this->transformNodes($query->getFrom(), $context);
        }

        //
        // If the query is a subquery and should return only one record, we set the limit to 1.
        //
        if ($query instanceof SubqueryInterface && $query->shouldReturnOnlyOne() && $query->getLimit()->getLimit() === 0) {
            $query->getLimit()->setLimit(1);
        }

        // After normalization, we call the postHandleQuery method
        if ($queryHandler instanceof QueryPostHandlerInterface) {
            $queryHandler->postHandleQuery($query, $context);
        }
    }

    protected function defineFactories(NodeContextInterface $context): void
    {
        $context->defineTransformerFactory(static function (NodeInterface $node, NodeContextInterface $context) {
            (new self())->transformNodes($node, $context, true);
        });

        $context->defineTransformerIteratorFactory(static function (NodeInterface $node, NodeContextInterface $context, ?NodeInterface $current = null) {
            new RecursiveIteratorByNodeIterator(new NodeTransformerIterator($node, current: $current));
        });
    }

    protected function normalizeMainEntity(QueryInterface $query, NodeContextInterface $context): void
    {
        $mainEntityName       = $query->findMainSubject()?->getSubjectName();

        //
        // Derived Entity case
        //
        if (empty($mainEntityName) && ($subquery = $query->getFrom()->getSubquery()) !== null) {

            $derivedEntity     = new DerivedEntity(
                $subquery,
                $query->getFrom()->getSubject(),
                entityFactory: $context
            );

            $context->setEntity($derivedEntity);
            $query->setMainEntityName($derivedEntity->getEntityName());
        } else {
            $query->setMainEntityName($mainEntityName);
        }
    }

    public function transformSubjects(QueryInterface $query, NodeContextInterface $context): void
    {
        $handler                = $context->getQueryHandler();

        if (false === $handler instanceof SubjectHandlerInterface) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new NodeRecursiveIteratorBySubject($query->getFrom()), \RecursiveIteratorIterator::SELF_FIRST
        ) as $subject) {
            $handler->handleSubject($subject, $context);
        }
    }

    /**
     * The method transforms all child nodes of the specified node.
     * However, the specified node itself is not processed.
     *
     * @throws TransformationException
     */
    protected function transformNodes(NodeInterface $queryNode, NodeContextInterface $basicContext, bool $includeSelf = false): void
    {
        $iterator               = new RecursiveIteratorByNodeIterator(new NodeTransformerIterator($queryNode, includeSelf: $includeSelf));

        foreach ($iterator as $node) {
            /* @var NodeInterface $node */
            $context            = $this->resolveContext($node, $basicContext);
            $node->transformWith($this->defineNodeHandler($node, $context), $context);
        }
    }

    protected function resolveContext(NodeInterface $node, NodeContextInterface $basicContext): NodeContextInterface
    {
        if ($node instanceof QueryInterface) {
            $context            = $this->newQueryContext($node, $basicContext);
        } else {
            $context            = $node->closestParentContext() ?? $basicContext;
        }

        $node->setNodeContext($context);

        return $context;
    }

    protected function defineNodeHandler(NodeInterface $node, NodeContextInterface $context): callable|null
    {
        $queryHandler       = $context->getQueryHandler();
        $columnHandler      = $context->getColumnHandler();
        $parameterHandler   = $context->getParameterHandler();

        return match (true) {
            $node instanceof ColumnInterface && $columnHandler !== null
                            => $columnHandler->handleColumn(...),
            $node instanceof ConstantInterface && $parameterHandler !== null
                            => $parameterHandler->handleParameter(...),
            $node instanceof SubjectInterface && $queryHandler instanceof SubjectHandlerInterface
                            => $queryHandler->handleSubject(...),
            $node instanceof JoinInterface && $queryHandler instanceof SubjectHandlerInterface
                            => $queryHandler->handleJoin(...),
            $node instanceof Using
                            => static function (Using $node, NodeContextInterface $context) {
                                $node->resolveAlias(static fn(string $alias) => $context->findAlias($alias));
                            },
            $node instanceof WhereEntity
                            => new WhereEntityTransformer(),
            $node instanceof FunctionReferenceInterface && $queryHandler instanceof FunctionHandlerInterface
                            => static function (FunctionReferenceInterface $node, NodeContextInterface $context) use ($queryHandler) {
                                if (false === $node->isResolved()) {
                                    $queryHandler->handleFunction($node, $context);
                                }
                            },
            $node instanceof SubqueryInterface
                            => $this,
            $node instanceof WithInterface
                            => new CteTransformer($this),
            $node instanceof RelationInterface
                            => static fn(RelationInterface $node) => $node->generateConditionsAndApply(),
            default         => null,
        };
    }

    protected function newQueryContext(BasicQueryInterface $query, NodeContextInterface $basicContext): NodeContextInterface
    {
        if ($query instanceof SubqueryInterface) {
            $context                = $this->newSubqueryContext($query, $basicContext);
        } else {
            $context                = $basicContext->newNodeContext($query);

            if ($query instanceof SelectInterface && $query->getUnionType() !== null) {
                $context->notInheritAliases();
            }
        }

        // Link the context to the basic context
        // This action defines the lifespan of the context.
        // The context lives as long as the main context exists in memory.
        $basicContext->addToInherited($context);

        return $context;
    }

    protected function newSubqueryContext(SubqueryInterface $subquery, NodeContextInterface $context): NodeContextInterface
    {
        $newContext                 = $context->newNodeContext($subquery, foreignContext: $context);
        $newContext->setAliasesNamespace($context->defineAlias($context->getCurrentEntity()) . '_');

        return $newContext;
    }
}
