<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Preprocessing;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Result\ResultInterface;
use IfCastle\AQL\Transaction\TransactionInterface;
use IfCastle\DI\DisposableInterface;
use IfCastle\Exceptions\UnexpectedValueType;

class PreprocessedQuery implements PreprocessedQueryInterface, DisposableInterface
{
    private mixed $queryProvider;

    private BasicQueryInterface|null $query = null;

    private mixed $executor         = null;

    /**
     * @param callable(): BasicQueryInterface $queryProvider builds a new query on every call
     */
    public function __construct(callable $queryProvider, private readonly string $uniqueKey = '')
    {
        $this->queryProvider        = $queryProvider;
    }

    #[\Override]
    public function getUniqueKey(): string
    {
        return $this->uniqueKey;
    }

    #[\Override]
    public function wasPreprocessed(): bool
    {
        return $this->executor !== null;
    }

    /**
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function getQuery(): BasicQueryInterface
    {
        $this->query                ??= $this->newQuery();

        return $this->query;
    }

    /**
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function newQuery(): BasicQueryInterface
    {
        $query                      = $this->queryProvider === null ? null : ($this->queryProvider)();

        if (false === $query instanceof BasicQueryInterface) {
            throw new UnexpectedValueType('query', $query, BasicQueryInterface::class);
        }

        return $query;
    }

    #[\Override]
    public function defineExecutor(callable $executor, BasicQueryInterface $query): void
    {
        if ($this->executor !== null) {
            throw new \LogicException('Executor already defined');
        }

        $this->executor              = $executor;
        $this->query                 = $query;
    }

    #[\Override]
    public function executeQuery(?TransactionInterface $transaction = null): ResultInterface
    {
        if ($this->executor === null) {
            throw new \LogicException('Executor not defined');
        }

        return ($this->executor)($transaction);
    }

    #[\Override]
    public function dispose(): void
    {
        $this->query                = null;
        $this->queryProvider        = null;
        $this->executor             = null;
    }
}
