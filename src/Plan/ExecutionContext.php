<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Plan;

use IfCastle\AQL\Result\ResultInterface;
use IfCastle\AQL\Transaction\TransactionInterface;
use IfCastle\DI\Container;
use IfCastle\DI\ContainerInterface;
use IfCastle\DI\ContainerMutableTrait;
use IfCastle\DI\DisposableInterface;
use IfCastle\DI\Resolver;
use IfCastle\Exceptions\CompositeException;
use IfCastle\Exceptions\UnexpectedValueType;

class ExecutionContext extends Container implements ExecutionContextInterface
{
    use ContainerMutableTrait;

    private readonly \WeakReference|null $executionPlan;

    protected string|null $stage    = null;

    protected ResultInterface|null $result = null;

    protected TransactionInterface|null $transaction = null;

    public function __construct(?ExecutionPlanInterface $executionPlan = null, array $data = [], ?ContainerInterface $parent = null)
    {
        parent::__construct(new Resolver(), $data, $parent, true);

        $this->executionPlan = $executionPlan === null ? null : \WeakReference::create($executionPlan);
    }

    /**
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function getExecutionPlan(): ExecutionPlanInterface
    {
        $object                     = $this->executionPlan?->get();

        if ($object instanceof ExecutionPlanInterface) {
            return $object;
        }

        throw new UnexpectedValueType('executionPlan', null, ExecutionPlanInterface::class);
    }

    #[\Override]
    public function findExecutionPlan(): ?ExecutionPlanInterface
    {
        return $this->executionPlan?->get();
    }

    #[\Override]
    public function getResult(): ?ResultInterface
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof ExecutionContextInterface) {
            return $parent->getResult();
        }

        return null;
    }

    #[\Override]
    public function setResult(ResultInterface $result): void
    {
        $this->result               = $result;
    }

    #[\Override]
    public function getStage(): ?string
    {
        if ($this->stage !== null) {
            return $this->stage;
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof ExecutionContextInterface) {
            return $parent->getStage();
        }

        return null;
    }

    #[\Override]
    public function setStage(string $stage): void
    {
        $this->stage                = $stage;
    }

    #[\Override]
    public function getTransaction(): ?TransactionInterface
    {
        if ($this->transaction !== null) {
            return $this->transaction;
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof ExecutionContextInterface) {
            return $parent->getTransaction();
        }

        return null;
    }

    #[\Override]
    public function withTransaction(?TransactionInterface $transaction = null): static
    {
        if ($transaction === null) {
            return $this;
        }

        $this->transaction          = $transaction;
        return $this;
    }

    #[\Override]
    public function getParentExecutionContext(): ?ExecutionContextInterface
    {
        $parent                     = $this->getParentContainer();

        if ($parent instanceof ExecutionContextInterface) {
            return $parent;
        }

        return null;
    }

    #[\Override]
    public function dispose(): void
    {
        $errors                     = [];

        try {
            parent::dispose();
        } catch (\Throwable $throwable) {
            $errors[]               = $throwable;
        }

        $transaction                = $this->transaction;

        $this->result               = null;
        $this->transaction          = null;

        try {
            if ($transaction instanceof DisposableInterface) {
                $transaction->dispose();
            }
        } catch (\Throwable $throwable) {
            $errors[]               = $throwable;
        }

        if (\count($errors) === 1) {
            throw \array_pop($errors);
        }

        if (\count($errors) > 1) {
            throw new CompositeException('Multiple exceptions occurred while ExecutionContext dispose', ...$errors);
        }
    }
}
