<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Resolver;

use IfCastle\AQL\Dsl\Sql\Column\Column;
use IfCastle\AQL\Dsl\Sql\Column\ColumnInterface;
use IfCastle\AQL\Dsl\Sql\Conditions\TupleConditionsInterface;
use IfCastle\AQL\Dsl\Sql\Parameter\Parameter;
use IfCastle\AQL\Dsl\Sql\Parameter\ParameterInterface;
use IfCastle\AQL\Dsl\Sql\Parameter\ParameterTuple;
use IfCastle\AQL\Dsl\Sql\Parameter\ParameterTupleInterface;
use IfCastle\AQL\Dsl\Sql\Tuple\TupleColumn;
use IfCastle\AQL\Dsl\Sql\Tuple\TupleColumnInterface;
use IfCastle\AQL\Entity\Relation\DirectRelationInterface;
use IfCastle\AQL\Executor\Exceptions\QueryException;
use IfCastle\AQL\Executor\Plan\ResultReaderInterface;
use IfCastle\AQL\Executor\Plan\SqlQueryCommandInterface;
use IfCastle\AQL\Result\ResultInterface;
use IfCastle\DI\DisposableInterface;

/**
 * Class for resolving relationships between two entities for which two different queries are made.
 * The class converts the relationship on the left side into a where clause, and adds the right side to a tuple.
 *
 * # Explanation
 *
 * There are two entities `$fromEntity` and `$toEntity` that are connected by inconsistent relationships.
 * That is, a single SQL query cannot be compiled for them.
 *
 * **Left side connection means**:
 * First, a query must be executed to the `$fromEntity`,
 * and then to the `$toEntity`.
 *
 * **Right side connection means**:
 * First, a query must be executed to the `$toEntity`,
 * and then to the `$fromEntity`.
 */
class SqlRelationResolverForSelect implements ResultReaderInterface, DisposableInterface
{
    /**
     * The method resolves a relationship between two queries that are executed separately.
     * The $fromCommand-query is executed first, the $toCommand-query is dependent on the one.
     * $relation describes the relationship from $fromCommand to $toCommand query.
     *
     * * fromCommand - left side of the relationship
     * * rightEntityName - right side of the relationship
     *
     * The method only supports relationships that can be converted to TupleConditionsI.
     *
     *
     * @throws QueryException
     */
    public static function resolve(SqlQueryCommandInterface $fromCommand, DirectRelationInterface $relation, SqlQueryCommandInterface $toCommand): void
    {
        // need reverse?
        if ($relation->isRightDependedOnLeft()) {
            [$fromCommand, $toCommand] = [$toCommand, $fromCommand];
            $relation               = $relation->reverseRelation();
        }

        self::restrictByKeys($fromCommand, $relation, $toCommand);
    }

    /**
     * Restricts the $toCommand query to the rows related to those the $fromCommand query reads: $fromCommand reads
     * the key of the relation as a hidden column, and $toCommand compares its side of the relation with the list of
     * keys read. $fromCommand must run first; its entity is the left one of $relation.
     *
     * @throws QueryException when the relation cannot be compared with a list of keys
     */
    public static function restrictByKeys(
        SqlQueryCommandInterface $fromCommand,
        DirectRelationInterface $relation,
        SqlQueryCommandInterface $toCommand
    ): void {
        $leftEntityName             = $relation->getLeftEntityName();
        $tuple                      = $fromCommand->getContainedSqlQuery()->getTuple();
        $tupleColumns               = [];

        // 1. Need add to $fromCommand results left key from $relation
        foreach ($relation->getLeftKey()->getKeyColumns() as $column) {
            $tupleColumn            = new TupleColumn(new Column($column, $leftEntityName));
            $tupleColumns[$column]  = $tupleColumn;
            $tuple->addHiddenColumn($tupleColumn);
        }

        $conditions                 = $relation->generateConditions();

        if ($conditions instanceof TupleConditionsInterface === false) {
            throw new QueryException([
                'query'             => $fromCommand->getContainedQuery(),
                'relation'          => \get_debug_type($relation),
                'from'              => $relation->getLeftEntityName(),
                'to'                => $relation->getRightEntityName(),
                'conditions'        => \get_debug_type($conditions),
                'template'          => 'Can\'t handle relation {relation} {from} => {to} with conditions {conditions} '
                . 'not instance of TupleConditionsI',
            ]);
        }

        $columns                    = \array_map(fn(ColumnInterface $column) => $column->getColumnName(), $conditions->getRightColumns());
        $parameter                  = \count($columns) > 1
                                    ? new ParameterTuple($columns) : new Parameter($columns[0], isValueList: true);

        $conditions->substituteRightExpression($parameter);

        $fromCommand->getResultProcessingPlan()->addResultReader(new self($tupleColumns, $parameter));

        // 2. Add dependencies to WHERE
        $toCommand->getContainedSqlQuery()->getWhere()->add($conditions);
    }

    /**
     * @param TupleColumnInterface[] $tupleColumns
     */
    public function __construct(
        protected array $tupleColumns,
        protected ParameterInterface $parameter
    ) {}

    #[\Override]
    public function dispose(): void
    {
        $this->tupleColumns         = [];
    }

    #[\Override]
    public function __invoke(...$args): mixed
    {
        $this->readResult(...$args);
        return null;
    }

    #[\Override]
    public function readResult(ResultInterface $result): void
    {
        $values                     = [];

        if ($this->parameter instanceof ParameterTupleInterface) {

            $columns                = $this->defineTupleAliases();

            foreach ($result as $row) {

                $tuple              = [];
                $isDefined          = false;

                foreach ($columns as $column) {

                    if (\array_key_exists($column, $row)) {
                        $isDefined  = true;
                    }

                    $tuple[]        = $row[$column] ?? null;
                }

                if ($isDefined) {
                    $values[]       = $tuple;
                }
            }

        } else {

            $aliases                = $this->defineTupleAliases();

            if ($aliases !== []) {
                $column             = $aliases[0];

                foreach ($result as $row) {
                    if (\array_key_exists($column, $row)) {
                        $values[]   = $row[$column];
                    }
                }
            }
        }

        $this->parameter->setParameterValue($values);
    }

    protected function defineTupleAliases(): array
    {
        return \array_values(\array_map(static fn(TupleColumnInterface $tupleColumn) => $tupleColumn->getAliasOrColumnName(), $this->tupleColumns));
    }
}
