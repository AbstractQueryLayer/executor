<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Preprocessing;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Result\ResultInterface;
use IfCastle\AQL\Transaction\TransactionInterface;

/**
 * An executor that runs the query it preprocessed again, without normalising it anew.
 */
interface PreprocessedQueryExecutorInterface
{
    /**
     * True while executePreprocessed() runs: a run that overlaps it, from another coroutine, must not use this
     * executor, since the transaction of a run is bound to the executor for the time of the run.
     */
    public function isExecuting(): bool;

    /**
     * Runs $query in $transaction, or outside any transaction when it is null. Nothing of the run stays bound to
     * the executor after it returns.
     *
     * @throws \LogicException when $query is not the query this executor preprocessed, or a run is in progress
     */
    public function executePreprocessed(BasicQueryInterface $query, ?TransactionInterface $transaction = null): ResultInterface;
}
