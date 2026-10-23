<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Resolver;

use IfCastle\AQL\Dsl\Node\NodeInterface;
use IfCastle\AQL\Dsl\Node\NodeRecursiveIterator;
use IfCastle\AQL\Dsl\Sql\Column\ColumnInterface;
use IfCastle\AQL\Dsl\Sql\Constant\ConstantInterface;
use IfCastle\AQL\Dsl\Sql\FunctionReference\FunctionReferenceInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\JoinInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Operation\LROperationInterface;
use IfCastle\AQL\Dsl\Sql\Query\QueryInterface;
use IfCastle\AQL\Dsl\Sql\Query\SelectInterface;
use IfCastle\AQL\Dsl\Sql\Tuple\TupleColumnInterface;
use IfCastle\AQL\Entity\EntityInterface;
use IfCastle\AQL\Entity\Relation\DirectRelationInterface;
use IfCastle\AQL\Executor\Exceptions\QueryException;

/**
 * The rules of a SELECT that joins an entity of another storage row by row (StorageJoinComposer): the rows of both
 * queries are joined after they are read, so whatever SQL computes over the joined rows (grouping, aggregates, an
 * order by the other entity, a LIMIT that counts joined rows) cannot run in either query and is refused.
 */
final class SqlRelationResolverForJoin
{
    /** Aggregate functions of MariaDB: they compute over the joined rows. */
    private const array AGGREGATES  = [
        'AVG', 'BIT_AND', 'BIT_OR', 'BIT_XOR', 'COUNT', 'GROUP_CONCAT', 'JSON_ARRAYAGG', 'JSON_OBJECTAGG', 'MAX',
        'MIN', 'STD', 'STDDEV', 'STDDEV_POP', 'STDDEV_SAMP', 'SUM', 'VAR_POP', 'VAR_SAMP', 'VARIANCE',
    ];

    /**
     * Whether $node names a column of the entity joined by $join, by the entity's name or by the join's alias.
     * $resolveSubstitution reads the node as it renders: a WHERE without the conditions taken out of it
     * (takeNullFilters). Without it the node is read as written: a tuple expression moved to the other query renders a
     * placeholder in its place, which names no entity.
     */
    public static function namesJoinedEntity(
        NodeInterface $node,
        JoinInterface $join,
        EntityInterface $entity,
        bool $resolveSubstitution = false
    ): bool {
        foreach (self::columns($node, $resolveSubstitution) as $column) {
            if (self::isColumnOf($column, $join, $entity)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws QueryException when $query computes over the joined rows
     */
    public static function refuseUncomposable(QueryInterface $query, JoinInterface $join, EntityInterface $entity): void
    {
        $reason                     = match (true) {
            self::inUnion($query)                         => 'a UNION reads the key of the join in one member only',
            ($query->getGroupBy()?->getChildNodes() ?? []) !== [] => 'GROUP BY groups the joined rows',
            $query->getHaving()?->isConditionsExist() === true => 'HAVING filters the joined groups',
            self::selectsEveryColumn($query)              => 'SELECT * names no columns of the joined entity',
            self::hasAggregate($query)                    => 'an aggregate computes over the joined rows',
            self::ordersByJoinedEntity($query, $join, $entity) => 'ORDER BY sorts by a column of the joined entity',
            $join->hasChildJoins()                         => 'a join under it is not composed',
            default                                        => null,
        };

        if ($reason !== null) {
            throw self::refusal($query, $entity, $reason);
        }
    }

    /**
     * Takes out of the WHERE of $query its conditions `X.column IS NULL` on the entity of a LEFT join: true for a
     * missing row, they are evaluated on the joined rows instead. `IS NOT NULL` is false for a missing row, so it stays
     * and runs in the query of the entity, as `=` does.
     *
     * @return list<string> the columns of the entity that must be NULL
     */
    public static function takeNullFilters(QueryInterface $query, JoinInterface $join, EntityInterface $entity): array
    {
        $filters                    = [];

        foreach ($query->getWhere()?->getChildNodes() ?? [] as $condition) {
            if (false === $condition instanceof LROperationInterface) {
                continue;
            }

            $operation              = \strtoupper($condition->getOperation());
            $column                 = $condition->getLeftNode();
            $right                  = $condition->getRightNode();

            if ($operation === 'IS' && $column instanceof ColumnInterface && self::isColumnOf($column, $join, $entity)
                && self::isNull($right)) {
                $filters[]          = $column->getColumnName();
                $condition->substituteToNullNode();
            }
        }

        return $filters;
    }

    /**
     * Whether the join keeps each row of the main query exactly once, so a LIMIT or a COUNT of the main query holds for
     * the joined rows: the joined entity is on the to-one side, and no main row is dropped after the queries ran (a
     * LEFT join, or one restricted to the rows that have a related row, without filters evaluated on the joined rows).
     *
     * @param DirectRelationInterface $relation from the main entity to $entity
     */
    public static function keepsEachRowOnce(
        EntityInterface $entity,
        DirectRelationInterface $relation,
        string $joinType,
        bool $isRestricted,
        bool $hasNullFilters
    ): bool {
        $primaryKey                 = $entity->getPrimaryKey()?->getKeyColumns() ?? [];
        $isToOne                    = $primaryKey !== []
                                      && \array_diff($primaryKey, $relation->getRightKey()->getKeyColumns()) === [];

        return $isToOne && false === $hasNullFilters && ($joinType === JoinInterface::LEFT || $isRestricted);
    }

    /**
     * @throws QueryException when $query counts or limits rows that the join would repeat or drop
     */
    public static function refuseCounting(QueryInterface $query, EntityInterface $entity, bool $keepsEachRowOnce): void
    {
        if ($keepsEachRowOnce) {
            return;
        }

        if ($query->getResolvedAction() === QueryInterface::ACTION_COUNT) {
            throw self::refusal($query, $entity, 'a COUNT counts the rows before the join');
        }

        if ($query->getLimit()?->isNotEmpty() === true) {
            throw self::refusal($query, $entity, 'a LIMIT counts the rows before the join');
        }
    }

    /**
     * @throws QueryException when $expression, moved to the query of $entity, names a column that is not of $entity
     */
    public static function refuseMixedExpression(NodeInterface $expression, EntityInterface $entity, callable $entityOf, QueryInterface $query): void
    {
        foreach (self::columns($expression) as $column) {
            $entityName             = $column->getEntityName();

            if ($entityName === null || $entityOf($entityName) !== $entity->getEntityName()) {
                throw new QueryException([
                    'template'      => 'The expression {expression} runs in the query of {entity}, in another storage,'
                                       . ' so each of its columns must name {entity}: {query}',
                    'expression'    => $expression->getAql(),
                    'entity'        => $entity->getEntityName(),
                    'query'         => $query->getAql(),
                ]);
            }
        }
    }

    private static function selectsEveryColumn(QueryInterface $query): bool
    {
        $tuple                      = $query->getTuple();

        return $tuple !== null && $tuple->isDefaultColumns() && $tuple->getTupleColumns() === [];
    }

    private static function isNull(?NodeInterface $node): bool
    {
        return $node instanceof ConstantInterface && $node->getConstantValue() === null;
    }

    /**
     * A UNION of the query, or the query as a member of one: the key of the join would be read by one member only.
     */
    private static function inUnion(QueryInterface $query): bool
    {
        return $query instanceof SelectInterface
            && ($query->getUnionType() !== null || false === $query->getUnion()->getQueries()->isEmpty());
    }

    /**
     * An aggregate of a SELECT; the COUNT of a COUNT query is decided by refuseCounting().
     */
    private static function hasAggregate(QueryInterface $query): bool
    {
        if ($query->getResolvedAction() === QueryInterface::ACTION_COUNT) {
            return false;
        }

        foreach (self::nodes($query->getTuple()) as $node) {
            if ($node instanceof FunctionReferenceInterface
                && \in_array(\strtoupper($node->getFunctionName()), self::AGGREGATES, true)) {
                return true;
            }
        }

        return false;
    }

    private static function ordersByJoinedEntity(QueryInterface $query, JoinInterface $join, EntityInterface $entity): bool
    {
        $orderBy                    = $query->getOrderBy();

        if ($orderBy === null || $orderBy->getChildNodes() === []) {
            return false;
        }

        // A column of the tuple that names the entity may be ordered by its alias.
        $aliases                    = [];

        foreach ($query->getTuple()?->getTupleColumns() ?? [] as $tupleColumn) {
            if ($tupleColumn instanceof TupleColumnInterface && self::namesJoinedEntity($tupleColumn, $join, $entity)) {
                $aliases[]          = $tupleColumn->getAliasOrColumnName();
            }
        }

        foreach (self::columns($orderBy) as $column) {
            if (self::isColumnOf($column, $join, $entity)
                || ($column->getEntityName() === null && \in_array($column->getColumnName(), $aliases, true))) {
                return true;
            }
        }

        return false;
    }

    private static function isColumnOf(ColumnInterface $column, JoinInterface $join, EntityInterface $entity): bool
    {
        $entityName                 = $column->getEntityName();

        return $entityName !== null && ($entityName === $entity->getEntityName() || $entityName === $join->getAlias());
    }

    /**
     * @return iterable<ColumnInterface>
     */
    private static function columns(?NodeInterface $node, bool $resolveSubstitution = false): iterable
    {
        foreach (self::nodes($node, $resolveSubstitution) as $child) {
            if ($child instanceof ColumnInterface) {
                yield $child;
            }
        }
    }

    /**
     * $node and every node under it.
     *
     * @return iterable<NodeInterface>
     */
    private static function nodes(?NodeInterface $node, bool $resolveSubstitution = false): iterable
    {
        if ($node === null) {
            return;
        }

        yield $node;

        $iterator                   = new NodeRecursiveIterator($node, $resolveSubstitution);

        foreach (new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::SELF_FIRST) as $child) {
            yield $child;
        }
    }

    private static function refusal(QueryInterface $query, EntityInterface $entity, string $reason): QueryException
    {
        return new QueryException([
            'template'          => 'The query joins {entity} of another storage row by row after both queries ran, but'
                                   . ' {reason}: {query}',
            'entity'            => $entity->getEntityName(),
            'reason'            => $reason,
            'query'             => $query->getAql(),
        ]);
    }
}
