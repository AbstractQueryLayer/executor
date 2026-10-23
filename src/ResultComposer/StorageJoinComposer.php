<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\ResultComposer;

use IfCastle\AQL\Dsl\Sql\Query\Expression\JoinInterface;
use IfCastle\AQL\Executor\Plan\ExecutionContextInterface;
use IfCastle\AQL\Executor\Plan\ResultHandlerInterface;
use IfCastle\AQL\Result\ResultInterface;
use IfCastle\AQL\Result\TupleInterface;
use IfCastle\DI\DisposableInterface;

/**
 * Joins the rows of an entity read from another storage to the rows of the main query, as one SQL statement joins
 * them: a main row is repeated for each related row, with the columns of that row written into the slots the main
 * query keeps for them; a main row without a related row is dropped by an INNER join and kept once, with NULL in those
 * columns, by a LEFT one. The two sides are related by key columns both queries read as hidden columns.
 *
 * Keys are compared as PHP values, by their JSON form: a key with a NULL part matches nothing, as in SQL. A string key
 * compared by a case-insensitive or padding collation in SQL ('A' = 'a ') does not match here.
 *
 * The composer holds the rows of the other entity of one run only: the plan of a preprocessed query runs it again.
 */
final class StorageJoinComposer implements ResultHandlerInterface, DisposableInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $relatedRows      = [];

    /**
     * Column aliases of the main row => aliases of the related row whose value they take.
     *
     * @var array<string, string>
     */
    private array $columns          = [];

    /**
     * Aliases of the related row that must be NULL: `IS NULL` conditions of a LEFT join evaluated on the joined row,
     * where a missing related row reads as NULL.
     *
     * @var list<string>
     */
    private array $nullFilters      = [];

    /**
     * @param string       $joinType       JoinInterface::INNER or JoinInterface::LEFT
     * @param list<string> $mainKeys       aliases of the key columns in the main row
     * @param list<string> $relatedKeys    aliases of the key columns in the related row, in the order of $mainKeys
     */
    public function __construct(
        private readonly string $joinType,
        private readonly array  $mainKeys,
        private readonly array  $relatedKeys
    ) {}

    /**
     * The main row's $mainAlias takes the related row's $relatedAlias.
     */
    public function addColumn(string $mainAlias, string $relatedAlias): void
    {
        $this->columns[$mainAlias]  = $relatedAlias;
    }

    /**
     * Keeps only the joined rows whose related $relatedAlias is NULL.
     */
    public function addNullFilter(string $relatedAlias): void
    {
        $this->nullFilters[]        = $relatedAlias;
    }

    /**
     * Takes the rows of the other entity read by this run.
     */
    public function readResult(ResultInterface $result): void
    {
        $this->relatedRows          = $result instanceof TupleInterface ? $result->toArray() : [];
    }

    #[\Override]
    public function handleResult(ExecutionContextInterface $context): void
    {
        $result                     = $context->getResult();

        if ($result instanceof TupleInterface) {
            $result->modify($this->join($result->toArray()));
        }

        $this->relatedRows          = [];
    }

    /**
     * @param list<array<string, mixed>> $mainRows
     * @return list<array<string, mixed>>
     */
    private function join(array $mainRows): array
    {
        $index                      = [];

        foreach ($this->relatedRows as $row) {
            $key                    = self::key($row, $this->relatedKeys);

            if ($key !== null) {
                $index[$key][]      = $row;
            }
        }

        $joined                     = [];

        foreach ($mainRows as $mainRow) {
            $key                    = self::key($mainRow, $this->mainKeys);
            $related                = $key === null ? [] : $index[$key] ?? [];

            if ($related === [] && $this->joinType === JoinInterface::LEFT) {
                $related            = [[]];
            }

            foreach ($related as $relatedRow) {
                if ($this->passes($relatedRow)) {
                    $joined[]       = $this->compose($mainRow, $relatedRow);
                }
            }
        }

        return $joined;
    }

    /**
     * @param array<string, mixed> $relatedRow an empty array for a LEFT join without a related row
     */
    private function passes(array $relatedRow): bool
    {
        foreach ($this->nullFilters as $alias) {
            if (($relatedRow[$alias] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $mainRow
     * @param array<string, mixed> $relatedRow
     * @return array<string, mixed>
     */
    private function compose(array $mainRow, array $relatedRow): array
    {
        foreach ($this->columns as $mainAlias => $relatedAlias) {
            $mainRow[$mainAlias]    = $relatedRow[$relatedAlias] ?? null;
        }

        return $mainRow;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $aliases
     */
    private static function key(array $row, array $aliases): ?string
    {
        $values                     = [];

        foreach ($aliases as $alias) {
            $value                  = $row[$alias] ?? null;

            if ($value === null) {
                return null;
            }

            // MariaDB returns integers as strings or as integers by the driver's options: compare their text.
            $values[]               = \is_scalar($value) ? (string) $value : $value;
        }

        return \json_encode($values, \JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function dispose(): void
    {
        $this->relatedRows          = [];
    }

    #[\Override]
    public function __invoke(mixed ...$args): mixed
    {
        $context                    = $args[0] ?? null;

        if ($context instanceof ExecutionContextInterface) {
            $this->handleResult($context);
        }

        return null;
    }
}
