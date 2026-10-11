<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Plan;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Executor\Plan\ExecutionPlanInterface as QueryExecutionPlanInterface;
use IfCastle\AQL\Result\ResultInterface;
use IfCastle\AQL\Result\TupleInterface;
use IfCastle\DesignPatterns\ExecutionPlan\ExecutionPlanInterface;
use IfCastle\DesignPatterns\ExecutionPlan\WeakStaticClosureExecutor;

/**
 * Class QueryCommand.
 *
 * Handles the execution and lifecycle management of query commands.
 *
 * This class is an implementation of a command that executes a request.
 * It combines two Flows:
 *
 * BeforeAfter execution plan, which allows adding handlers BEFORE and AFTER the request.
 * ResultProcessingPlanInterface - a Flow for processing the request results.
 */
class QueryCommand extends Command implements QueryCommandInterface
{
    protected (ExecutionPlanInterface & ResultProcessingPlanInterface)|null $resultPlan = null;

    public function __construct(string $stage, callable $handler, protected ?BasicQueryInterface $query = null)
    {
        parent::__construct($stage, $handler);
    }

    #[\Override]
    protected function handleStage(mixed $handler, string $stage): void
    {
        $context                    = $this->context?->get();

        if ($handler instanceof ResultReaderInterface) {
            $handler->readResult($context->getResult());
        } elseif ($handler instanceof RowModifierInterface) {
            $result                 = $context->getResult();

            if ($result instanceof TupleInterface) {
                $rows               = $result->toArray();
                $handler->modifyResultRows($rows, $context);
                $result->modify($rows);
            }
        } elseif ($handler instanceof ResultHandlerInterface) {
            $handler->handleResult($context);
        } elseif ($handler instanceof ExecutionHandlerInterface) {
            $handler->executeHandler($context);
        } else {
            $handler($stage);
        }
    }

    #[\Override]
    public function executeHandler(?ExecutionContextInterface $context = null): void
    {
        $inheritedContext           = $this->inheritContext($context);
        $this->context              = \WeakReference::create($inheritedContext);

        try {
            if ($this->beforeAfterExecutor === null) {
                $this->executeQuery($inheritedContext);
            } else {
                $this->beforeAfterExecutor->addHandler(fn() => $this->executeQuery($inheritedContext))->executePlan();
            }

            $this->resultPlan?->executePlan();
        } finally {

            if ($inheritedContext !== $context) {
                $inheritedContext->dispose();
            }

            $this->context          = null;
        }
    }

    protected function inheritContext(?ExecutionContextInterface $context = null): ExecutionContextInterface
    {
        if ($this->stage === QueryExecutionPlanInterface::TARGET) {
            return $context;
        }

        return new QueryCommandContext($context);
    }

    protected function executeQuery(?ExecutionContextInterface $context = null): void
    {
        $executor                   = $this->handler;

        if ($executor instanceof ExecutionHandlerInterface) {
            $executor->executeHandler($context);
        } else {
            $result                 = $executor($context);

            if ($result instanceof ResultInterface) {
                $context->setResult($result);
            }
        }
    }

    #[\Override]
    public function __invoke(...$args): mixed
    {
        $this->executeHandler(...$args);
        return null;
    }

    #[\Override]
    public function getHandlerHash(): string|int|null
    {
        if ($this->query !== null) {
            return \spl_object_id($this->query);
        }

        return \spl_object_id($this);
    }

    #[\Override]
    public function getResultProcessingPlan(): ResultProcessingPlanInterface
    {
        if ($this->resultPlan !== null) {
            return $this->resultPlan;
        }

        $this->resultPlan           = new ResultProcessingPlan(
            new WeakStaticClosureExecutor(static fn(self $self, $handler, $stage) => $self->handleStage($handler, $stage), $this)
        );

        return $this->resultPlan;
    }

    #[\Override]
    public function getContainedQuery(): BasicQueryInterface
    {
        return $this->query;
    }

    #[\Override]
    public function dispose(): void
    {
        parent::dispose();

        $this->query                = null;
        $this->resultPlan           = null;
    }
}
