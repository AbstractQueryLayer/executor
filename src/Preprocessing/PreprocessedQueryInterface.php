<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Preprocessing;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Result\ResultInterface;
use IfCastle\AQL\Transaction\TransactionInterface;

/**
 * A query normalised once and run many times, each run in the transaction of its own call.
 */
interface PreprocessedQueryInterface
{
    /**
     * The key a PreprocessedQueryStorageInterface keeps the query under; an empty key is never stored.
     */
    public function getUniqueKey(): string;

    public function wasPreprocessed(): bool;

    /**
     * The query bound to the executor, or a new one from the provider before the binding. The bound query is run
     * only through executeQuery(): run directly, it would ignore the caller's transaction.
     */
    public function getQuery(): BasicQueryInterface;

    /**
     * A new query from the provider, for a run that cannot use the shared one.
     */
    public function newQuery(): BasicQueryInterface;

    /**
     * @param callable(?TransactionInterface): ResultInterface $executor runs $query once
     * @param BasicQueryInterface                             $query    the query the executor preprocessed
     *
     * @throws \LogicException when an executor is already defined
     */
    public function defineExecutor(callable $executor, BasicQueryInterface $query): void;

    /**
     * Runs the query in the given transaction, or outside any when it is null.
     *
     * @throws \LogicException when no executor is defined
     */
    public function executeQuery(?TransactionInterface $transaction = null): ResultInterface;
}
