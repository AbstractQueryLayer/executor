<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor;

use IfCastle\AQL\Dsl\Sql\Query\Exceptions\TransformationException;
use IfCastle\AQL\Dsl\Sql\Query\SubqueryInterface;
use IfCastle\AQL\Executor\Context\NodeContextInterface;
use IfCastle\AQL\Executor\Helpers\ContextHelper;

/**
 * Executive and SQL query converter.
 *
 */
class SqlQueryExecutor extends QueryExecutorAbstract implements
    QueryHandlerInterface,
    QueryPostHandlerInterface,
    ColumnHandlerInterface,
    FunctionHandlerInterface,
    OptionHandlerInterface,
    SubjectHandlerInterface
{
    #[\Override]
    protected function handleSubquery(SubqueryInterface $query, NodeContextInterface $context): void
    {
        $contextName                = ContextHelper::resolveContextName($query->getParentNode());

        // A scalar tuple subquery needs the implicit relation to its outer entity. A filter
        // subquery already states its membership through IN (or another filter operation).
        if ($contextName !== NodeContextInterface::CONTEXT_TUPLE) {
            return;
        }

        $leftEntity                 = $context->getEntity($query->getMainEntityName());
        $rightEntity                = $context->getParentContext()?->getCurrentEntity() ?? throw new TransformationException([
            'template'              => 'Expected parent context to be set for subquery {aql}',
            'aql'                   => $query->getAql(),
        ]);

        $relation                   = $rightEntity->resolveRelation($leftEntity);

        $query->returnOnlyOne();
        $query->getWhere()->add($relation->generateConditions());
    }
}
