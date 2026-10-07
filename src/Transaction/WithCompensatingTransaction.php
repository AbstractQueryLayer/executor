<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Transaction;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Executor\AdditionalHandlerAwareInterface;
use IfCastle\AQL\Executor\AdditionalOptionsInterface;
use IfCastle\AQL\Executor\AqlExecutorInterface;
use IfCastle\AQL\Executor\Plan\ExecutionContextInterface;
use IfCastle\AQL\Executor\Preprocessing\PreprocessedQueryInterface;
use IfCastle\AQL\Result\InsertUpdateResultInterface;
use IfCastle\AQL\Result\ResultInterface;
use IfCastle\AQL\Result\TupleInterface;
use IfCastle\AQL\Transaction\Transaction;
use IfCastle\AQL\Transaction\TransactionStatusEnum;

/**
 * Strategy for executing queries under a transaction.
 */
class WithCompensatingTransaction implements AqlExecutorInterface
{
    protected \WeakReference|null $transaction = null;

    /**
     * @var BasicQueryInterface[]
     */
    protected array $executedQueries = [];

    public function __construct(public readonly AqlExecutorInterface $aqlExecutor) {}

    #[\Override]
    public function executeAql(BasicQueryInterface|PreprocessedQueryInterface            $query,
        ExecutionContextInterface|AdditionalHandlerAwareInterface|AdditionalOptionsInterface|null $executionContext = null
    ): ResultInterface|TupleInterface|InsertUpdateResultInterface {
        $transaction = $this->transaction?->get() ?? throw new \LogicException('Execute queries inside run()');
        $result                     = $this->aqlExecutor->executeAql(
            $query, WithTransaction::addTransactionToContext($transaction, $executionContext)
        );

        $this->executedQueries[]    = $query;

        return $result;
    }

    #[\Override]
    public function preprocessingQuery(
        BasicQueryInterface $query,
        ?ExecutionContextInterface $executionContext = null
    ): void {
        $this->aqlExecutor->preprocessingQuery($query, $executionContext);
    }

    public function run(callable $function): mixed
    {
        $executor                   = clone $this;
        $executor->executedQueries  = [];
        $transaction                = new Transaction($executor->transactionHandler(...));
        $parent                     = $this->transaction?->get();

        if ($parent !== null) {
            $transaction->setParentTransaction($parent);
        }

        $executor->transaction      = \WeakReference::create($transaction);

        try {
            $executor->defineTransactionId();
            $result                 = $function($executor);
            $transaction->commit();

            return $result;
        } catch (\Throwable $throwable) {
            if ($transaction->getStatus() === TransactionStatusEnum::UNDEFINED
                || $transaction->getStatus() === TransactionStatusEnum::OPENED) {
                $transaction->rollBack($throwable);
            }
            throw $throwable;
        } finally {
            $executor->transaction       = null;
            $executor->executedQueries  = [];
        }
    }

    protected function defineTransactionId(): void
    {
        $transaction = $this->transaction?->get() ?? throw new \LogicException('No active transaction');

        if ($transaction->getTransactionId() === null) {
            $transaction->setTransactionId(\bin2hex(\random_bytes(16)));
        }
    }

    protected function transactionHandler(bool $isCommit): void {}
}
