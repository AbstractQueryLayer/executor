<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Dsl\Sql\Query\QueryInterface;
use IfCastle\AQL\Dsl\Sql\Query\WithInterface;
use IfCastle\AQL\Entity\Functions\FunctionStorageInterface;
use IfCastle\AQL\Entity\Manager\EntityFactoryInterface;
use IfCastle\AQL\Executor\Context\NodeContext;
use IfCastle\AQL\Executor\Context\NodeContextInterface;
use IfCastle\AQL\Executor\Exceptions\QueryException;
use IfCastle\AQL\Executor\Plan\CommandInterface;
use IfCastle\AQL\Executor\Plan\ExecutionContextInterface;
use IfCastle\AQL\Executor\Plan\ExecutionContext;
use IfCastle\AQL\Executor\Plan\ExecutionPlan;
use IfCastle\AQL\Executor\Plan\ExecutionPlanInterface;
use IfCastle\AQL\Executor\Plan\NormalizingPlan;
use IfCastle\AQL\Executor\Plan\NormalizingPlanInterface;
use IfCastle\AQL\Executor\Plan\QueryCommand;
use IfCastle\AQL\Executor\Plan\QueryCommandInterface;
use IfCastle\AQL\Executor\Plan\QueryPlanInterface;
use IfCastle\AQL\Executor\Plan\SqlQueryCommand;
use IfCastle\AQL\Executor\PostAction\PostActionCommand;
use IfCastle\AQL\Executor\PostAction\PostActionInterface;
use IfCastle\AQL\Executor\Transformer\NodeTransformer;
use IfCastle\AQL\Executor\Transformer\Sql\CteTransformer;
use IfCastle\AQL\Executor\Transformer\Sql\SqlTransformer;
use IfCastle\AQL\Result\ResultInterface;
use IfCastle\AQL\Storage\StorageCollectionInterface;
use IfCastle\DesignPatterns\ScopeControl\ScopeProcessorInterface;
use IfCastle\DI\AutoResolverInterface;
use IfCastle\DI\ContainerInterface;
use IfCastle\Exceptions\BaseException;
use IfCastle\Exceptions\UnexpectedValueType;

abstract class QueryExecutorBasicAbstract implements
    QueryExecutorWithPlanInterface,
    QueryPlanInterface,
    AutoResolverInterface
{
    protected ContainerInterface $container;

    // NodeContext keeps its parent weakly; retain the per-query context for this executor's lifetime.
    protected ?ExecutionContextInterface $executionContext = null;

    protected AqlExecutorInterface $aqlExecutor;

    protected EntityFactoryInterface $entityFactory;

    protected StorageCollectionInterface $storageCollection;

    protected ?FunctionStorageInterface $functionStorage = null;

    protected ?ScopeProcessorInterface $scopeProcessor  = null;

    /**
     * Context for transformation and normalization query.
     */
    protected \WeakReference|null $queryContext = null;

    protected ?ExecutionPlanInterface $executionPlan = null;

    protected mixed $additionalHandler          = null;

    protected array $postActions                = [];

    protected bool $wasProcessed                = false;

    #[\Override]
    public function resolveDependencies(ContainerInterface $container): void
    {
        $this->container            = $container;
        $this->aqlExecutor          = $container->resolveDependency(AqlExecutorInterface::class);
        $this->entityFactory        = $container->resolveDependency(EntityFactoryInterface::class);
        $this->storageCollection    = $container->findDependency(StorageCollectionInterface::class);
        $this->functionStorage      = $container->findDependency(FunctionStorageInterface::class);
    }

    #[\Override]
    public function executeQuery(BasicQueryInterface                                       $query,
        ExecutionContextInterface|AdditionalHandlerAwareInterface|AdditionalOptionsInterface|null $executionContext = null
    ): ResultInterface {
        if (false === $this->wasProcessed) {
            $this->preprocessing($query, $executionContext);
        }

        if ($this->executionPlan !== null) {
            return $this->executionPlan->executePlanAndReturnResult();
        }

        return $this->executeQueryWithContext($query);
    }

    #[\Override]
    public function preprocessing(
        BasicQueryInterface                                       $query,
        AdditionalHandlerAwareInterface|ExecutionContextInterface|AdditionalOptionsInterface|null $executionContext = null
    ): void {
        $this->wasProcessed         = true;
        $this->executionPlan        = null;

        if ($executionContext instanceof AdditionalHandlerAwareInterface) {
            $this->additionalHandler = $executionContext->getAdditionalHandler();
        }

        $executionContext = $this->defineQueryContext($query, $executionContext);
        $this->executionContext = $executionContext;
        $this->normalizeQueryOrGeneratePlan($query, $executionContext);
    }

    #[\Override]
    public function getExecutionPlan(): ExecutionPlanInterface
    {
        if ($this->executionPlan !== null) {
            return $this->executionPlan;
        }

        $this->initExecutionPlan();

        return $this->executionPlan;
    }

    #[\Override]
    public function findExecutionPlan(): ?ExecutionPlanInterface
    {
        return $this->executionPlan;
    }

    /**
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function getNormalizingPlan(): NormalizingPlanInterface
    {
        $plan                       = $this->getExecutionPlan()->getParentPlan();

        if ($plan === null) {
            $this->defineNormalizingPlan();
            $plan                   = $this->executionPlan->getParentPlan();
        }

        if ($plan instanceof NormalizingPlanInterface === false) {
            throw new UnexpectedValueType('$plan', $plan, NormalizingPlanInterface::class);
        }

        return $plan;
    }

    /**
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function findNormalizingPlan(): ?NormalizingPlanInterface
    {
        if ($this->getExecutionPlan()->getParentPlan() === null) {
            return null;
        }

        return $this->getNormalizingPlan();
    }

    #[\Override]
    public function getQueryPlan(): QueryPlanInterface
    {
        return $this;
    }

    #[\Override]
    public function newQueryCommand(BasicQueryInterface $query, string $target): QueryCommandInterface
    {
        $queryCommand               = new QueryCommand(
            $target, fn(?ExecutionContextInterface $context = null) => $context?->setResult($this->aqlExecutor->executeAql($query)), $query
        );

        $this->getExecutionPlan()->addCommand($queryCommand);

        return $queryCommand;
    }

    #[\Override]
    public function newQueryTargetCommand(BasicQueryInterface $query): QueryCommandInterface
    {
        return $this->newQueryCommand($query, ExecutionPlanInterface::TARGET);
    }

    #[\Override]
    public function newQueryRightCommand(BasicQueryInterface $query): QueryCommandInterface
    {
        return $this->newQueryCommand($query, ExecutionPlanInterface::RIGHT);
    }

    #[\Override]
    public function newQueryLeftCommand(BasicQueryInterface $query): QueryCommandInterface
    {
        return $this->newQueryCommand($query, ExecutionPlanInterface::LEFT);
    }

    /**
     * The purpose of this method is either to transform the query into another query or to generate an execution plan.
     */
    protected function normalizeQueryOrGeneratePlan(BasicQueryInterface $basicQuery, ?ExecutionContextInterface $context = null): void
    {
        NodeTransformer::transformWhileNotResolved(
            $basicQuery,
            $this->queryContext->get(),
            $basicQuery instanceof WithInterface ? new CteTransformer(new SqlTransformer()) : new SqlTransformer()
        );
    }

    /**
     * @throws QueryException
     * @throws BaseException
     */
    protected function initExecutionPlan(): void
    {
        $this->executionPlan        = new ExecutionPlan();

        // When the execution plan is just created,
        // we must immediately define the main query as part of the plan
        $command                    = $this->defineCommandByQuery($this->queryContext->get()->getBasicQuery());

        $this->executionPlan->addCommand($command);
    }

    protected function defineCommandByQuery(BasicQueryInterface $query): CommandInterface
    {
        if ($query instanceof QueryInterface) {
            // create command for SQL-compatible query
            return new SqlQueryCommand(
                ExecutionPlanInterface::TARGET,
                fn(?ExecutionContextInterface $context = null) => $this->executeQueryWithContext($query, $context),
                $query
            );
        }

        return new QueryCommand(
            ExecutionPlanInterface::TARGET,
            fn(?ExecutionContextInterface $context = null) => $this->executeQueryWithContext($query, $context),
            $query
        );

    }

    #[\Override]
    public function resolveQueryCommand(BasicQueryInterface $currentQuery, ?BasicQueryInterface $mainQuery = null): QueryCommandInterface
    {
        $executionPlan              = $this->getExecutionPlan();
        $queryCommand               = $executionPlan->findCommandByQuery($currentQuery);

        if ($queryCommand instanceof QueryCommandInterface) {
            return $queryCommand;
        }

        // determine the stage of the request relative to the base
        $stage                      = ExecutionPlanInterface::TARGET;
        $queryCommand               = new QueryCommand(
            $stage, fn(?ExecutionContextInterface $context = null) => $this->executeQueryWithContext($currentQuery, $context), $currentQuery
        );

        $this->getExecutionPlan()->addCommand($queryCommand);

        return $queryCommand;
    }

    #[\Override]
    public function dispose(): void
    {
        $this->executionPlan?->dispose();
        $this->executionPlan        = null;
        $this->queryContext?->get()?->dispose();
        $this->queryContext         = null;
    }

    /**
     * @throws UnexpectedValueType
     */
    protected function defineNormalizingPlan(?NodeContextInterface $context = null): void
    {
        $plan                       = $context !== null ? $context->getQueryExecutor()->getExecutionPlan() : $this->getExecutionPlan();

        $parentPlan                 = $plan->getParentPlan();

        if ($parentPlan === null) {
            $plan->setParentPlan(new NormalizingPlan());
            return;
        }

        if ($parentPlan instanceof NormalizingPlanInterface === false) {
            throw new UnexpectedValueType('$parentPlan', $parentPlan, NormalizingPlanInterface::class);
        }
    }

    #[\Override]
    public function addPostAction(PostActionInterface $postAction): void
    {
        $this->postActions[]       = $postAction;
        $this->getExecutionPlan()->addCommand(new PostActionCommand($postAction));
    }

    #[\Override] public function getPostActions(): array
    {
        return $this->postActions;
    }

    protected function defineQueryContext(
        BasicQueryInterface                                       $query,
        ExecutionContextInterface|AdditionalHandlerAwareInterface|AdditionalOptionsInterface|null $executionContext = null
    ): ?ExecutionContextInterface {
        if ($executionContext instanceof AdditionalOptionsInterface) {
            $executionContext       = (new ExecutionContext(data: $executionContext->getAdditionalOptions(), parent: $this->container))
                ->withTransaction($executionContext->getTransaction());
        } elseif ($executionContext instanceof ExecutionContextInterface === false) {
            $executionContext       = null;
        }

        $queryContext               = $query->getNodeContext();

        if ($queryContext instanceof NodeContextInterface) {
            $queryContext->setParentContainer($executionContext ?? $this->container);
        } else {
            $queryContext           = new NodeContext(
                currentNode  : $query,
                contextName  : NodeContextInterface::CONTEXT_QUERY,
                query        : $query,
                queryExecutor: $this,
                parent       : $executionContext ?? $this->container
            );

            $query->setNodeContext($queryContext);
        }

        $this->queryContext         = \WeakReference::create($queryContext);

        return $executionContext;
    }

    abstract protected function executeQueryWithContext(BasicQueryInterface $query, ?ExecutionContextInterface $context = null): ResultInterface;
}
