<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Plan;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Executor\Exceptions\QueryException;
use IfCastle\AQL\Executor\ResultComposer\StorageJoinComposer;
use IfCastle\AQL\Result\ResultInterface;
use IfCastle\AQL\Result\TupleInterface;
use IfCastle\DesignPatterns\ExecutionPlan\BeforeAfterPlanWithMapping;
use IfCastle\DesignPatterns\ExecutionPlan\HandlerExecutorWithResolverSetter;
use IfCastle\DesignPatterns\ExecutionPlan\InsertPositionEnum;
use IfCastle\DesignPatterns\ExecutionPlan\SequentialPlanExecutorWithFinal;
use IfCastle\DesignPatterns\ExecutionPlan\WeakStaticClosureExecutor;
use IfCastle\DI\DisposableInterface;
use IfCastle\Exceptions\BaseException;
use IfCastle\Exceptions\UnexpectedValueType;

class ExecutionPlan extends BeforeAfterPlanWithMapping implements ExecutionPlanInterface
{
    use ResultProcessingPlanTrait;
    use ResultComposingPlanTrait;

    protected ?\WeakReference $context = null;

    protected ?ExecutionPlanInterface $parentPlan = null;

    protected HandlerExecutorWithResolverSetter $executor;

    public function __construct(string ...$actions)
    {
        if ($actions === []) {

            //
            // Expression: <target data> + <left data> = <right data>
            // means to get the data on the left side that depends on the data on the right side of the expression.
            // All right side dependencies are executed first.
            // Then the target expression is calculated
            // and only then left additions.
            //
            //

            $actions                = [
                self::RIGHT,
                self::TARGET,
                self::LEFT,
                self::RAW_READER,
                self::ROW_MODIFIER,
                self::RESULT_READER,
                self::RESULT_COMPOSER,
                self::POST_READER,
                self::POST_ACTION,
                self::FINALLY,
            ];
        }

        parent::__construct(
            new WeakStaticClosureExecutor(static fn(self $self, $handler, $stage) => $self->handleStage($handler, $stage), $this),
            $actions,
            new SequentialPlanExecutorWithFinal()
        );
    }

    protected function handleStage(mixed $handler, string $stage): void
    {
        $context                    = $this->context?->get();

        if ($handler instanceof ResultReaderInterface) {
            $handler->readResult($context?->getResult());
        } elseif ($handler instanceof RowModifierInterface) {
            $result                 = $context?->getResult();

            if ($result instanceof TupleInterface) {
                $rows               = $result->toArray();
                $handler->modifyResultRows($rows, $context);
                $result->modify($rows);
                unset($rows);
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
    protected function setCurrentStage(string $stage): void
    {
        parent::setCurrentStage($stage);
        $this->context?->get()?->setStage($stage);
    }

    #[\Override]
    public function getParentPlan(): ?ExecutionPlanInterface
    {
        return $this->parentPlan;
    }

    #[\Override]
    public function setParentPlan(ExecutionPlanInterface $plan): static
    {
        $this->parentPlan           = $plan;
        return $this;
    }

    /**
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function findCommandByHash(string|int|null $hash): ?CommandInterface
    {
        $handler                    = $this->findHandlerByHash($hash);

        if ($handler === null) {
            return null;
        }

        if ($handler instanceof CommandInterface) {
            return $handler;
        }

        throw new UnexpectedValueType('$handler', $handler, CommandInterface::class);
    }

    /**
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function findCommandByQuery(BasicQueryInterface $query): ?QueryCommandInterface
    {
        $command                    = $this->findCommandByHash(\spl_object_id($query));

        if ($command instanceof QueryCommandInterface) {
            return $command;
        }

        throw new UnexpectedValueType('$command', $command, QueryCommandInterface::class);
    }

    /**
     * @throws QueryException
     * @throws BaseException
     */
    #[\Override]
    public function addCommand(
        CommandInterface $command,
        ?CommandInterface $afterCommand      = null,
        ?CommandInterface $beforeCommand     = null,
        bool             $toStart           = false
    ): static {
        return $this->addStageUniqueHandler(
            $command->getCommandStage(),
            $command,
            true,
            $afterCommand,
            $beforeCommand,
            $toStart ? InsertPositionEnum::TO_START : InsertPositionEnum::TO_END
        );
    }

    #[\Override]
    public function executePlanAndReturnResult(): ResultInterface
    {
        try {
            $context                = $this->context?->get();
            $context                ??= new ExecutionContext($this);
            $this->context          = \WeakReference::create($context);
            $this->executePlan();

            return $context->getResult();
        } finally {
            $this->context          = null;
            if ($context instanceof DisposableInterface) {
                $context->dispose();
            }
        }
    }

    /**
     * Whether a call may run the plan again: it holds only the commands of the main query, the queries that read
     * other storages for it and the composers of their rows, which keep the rows of one run. A command of any other
     * kind, another result composer or a row modifier may keep state of a run.
     */
    public function isRerunnable(): bool
    {
        foreach ($this->stages as $stage => $handlers) {
            foreach ($handlers as $handler) {
                if (false === $handler instanceof StorageJoinCommand
                    && ($stage !== self::TARGET || false === $handler instanceof QueryCommandInterface)
                    && ($stage !== self::RESULT_COMPOSER || false === $handler instanceof StorageJoinComposer)) {
                    return false;
                }
            }
        }

        return true;
    }

    #[\Override]
    public function getExecutionContext(): ?ExecutionContextInterface
    {
        return $this->context?->get();
    }

    #[\Override]
    public function dispose(): void
    {
        $context                    = $this->context?->get();
        $this->context              = null;

        if ($context instanceof DisposableInterface) {
            $context->dispose();
        }
    }
}
