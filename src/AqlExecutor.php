<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Dsl\Sql\Query\QueryInterface;
use IfCastle\AQL\Entity\EntityInterface;
use IfCastle\AQL\Entity\Manager\EntityFactoryInterface;
use IfCastle\AQL\Executor\Context\NodeContextInterface;
use IfCastle\AQL\Executor\Exceptions\QueryException;
use IfCastle\AQL\Executor\Exceptions\UnknownQueryAction;
use IfCastle\AQL\Executor\Plan\ExecutionContextInterface;
use IfCastle\AQL\Executor\Preprocessing\PreprocessedQueryExecutorInterface;
use IfCastle\AQL\Executor\Preprocessing\PreprocessedQueryInterface;
use IfCastle\AQL\Executor\Preprocessing\PreprocessedQueryStorageInterface;
use IfCastle\AQL\Result\InsertUpdateResultInterface;
use IfCastle\AQL\Result\ResultInterface;
use IfCastle\AQL\Result\TupleInterface;
use IfCastle\AQL\Storage\StorageCollectionInterface;
use IfCastle\AQL\Transaction\TransactionInterface;

class AqlExecutor implements AqlExecutorInterface
{
    public function __construct(
        protected EntityFactoryInterface $entityFactory,
        protected StorageCollectionInterface $storageCollection,
        protected QueryExecutorFactoryInterface|null $queryExecutorFactory = null,
        protected PreprocessedQueryStorageInterface|null $preprocessedQueryStorage = null
    ) {}

    /**
     *
     *
     * @throws QueryException
     * @throws UnknownQueryAction
     */
    #[\Override]
    public function executeAql(BasicQueryInterface|PreprocessedQueryInterface $query,
        ExecutionContextInterface|AdditionalHandlerAwareInterface|AdditionalOptionsInterface|null $executionContext = null
    ): ResultInterface|TupleInterface|InsertUpdateResultInterface {
        if ($query instanceof PreprocessedQueryInterface) {
            self::refuseShapingContext($executionContext);

            return $this->resolvePreprocessedQuery($query)
                        ->executeQuery(self::transactionOf($executionContext));
        }

        return $this->defineExecutor($query)->executeQuery($query, $executionContext);
    }

    #[\Override]
    public function preprocessingQuery(
        BasicQueryInterface $query,
        ?ExecutionContextInterface $executionContext = null
    ): void {
        $this->defineExecutor($query)->preprocessing($query, $executionContext);
    }

    protected function defineExecutor(BasicQueryInterface $query): QueryExecutorInterface
    {
        // If context already exists, then return executor from it
        $context                    = $query->getNodeContext();

        if ($context instanceof NodeContextInterface) {
            return $context->getQueryExecutor();
        }

        if ($this->isWithoutEntity($query)) {
            return $this->defineExecutorWithoutEntity($query);
        }

        $entity                     = $this->entityFactory->getEntity($this->defineEntityNameByQuery($query));
        $executor                   = null;

        if ($entity instanceof QueryExecutorResolverInterface) {
            $executor               = $entity->resolveQueryExecutor($query);
        }

        if ($executor === null) {
            return $this->defineDefaultExecutor($query, $entity);
        }

        return $executor;
    }

    /**
     * A SELECT without FROM reads no entity: it computes expressions on the server.
     */
    protected function isWithoutEntity(BasicQueryInterface $query): bool
    {
        return $query instanceof QueryInterface
            && $query->isSelect()
            && $query->getFrom() === null
            && $query->getMainEntityName() === '';
    }

    /**
     * The executor for a query that has no entity to name a storage: the default storage runs it.
     *
     * @throws QueryException
     * @throws UnknownQueryAction
     */
    protected function defineExecutorWithoutEntity(BasicQueryInterface $query): QueryExecutorInterface
    {
        $storage                    = $this->storageCollection->findStorage(null);
        $executor                   = $storage instanceof QueryExecutorResolverInterface
                                    ? $storage->resolveQueryExecutor($query) : null;

        return $executor ?? $this->defineDefaultExecutor($query);
    }

    /**
     * A preprocessed query is normalised once, without the caller's context, and shared by every call: an additional
     * handler or additional options would be ignored, so they are refused, and a call carries a transaction only.
     *
     * @throws \LogicException
     */
    protected static function refuseShapingContext(
        ExecutionContextInterface|AdditionalHandlerAwareInterface|AdditionalOptionsInterface|null $executionContext
    ): void {
        if ($executionContext instanceof AdditionalHandlerAwareInterface && $executionContext->getAdditionalHandler() !== null) {
            throw new \LogicException('A preprocessed query cannot take an additional handler');
        }

        if ($executionContext instanceof AdditionalOptionsInterface && $executionContext->getAdditionalOptions() !== []) {
            throw new \LogicException('A preprocessed query cannot take additional options');
        }
    }

    protected static function transactionOf(
        ExecutionContextInterface|AdditionalHandlerAwareInterface|AdditionalOptionsInterface|null $executionContext
    ): ?TransactionInterface {
        return $executionContext instanceof ExecutionContextInterface || $executionContext instanceof AdditionalOptionsInterface
            ? $executionContext->getTransaction()
            : null;
    }

    /**
     * Returns the query preprocessed and bound to its executor: the one stored under its key, or this one, which is
     * stored once it is bound. A query that fails to preprocess is neither bound nor stored.
     *
     * @throws QueryException
     */
    protected function resolvePreprocessedQuery(
        PreprocessedQueryInterface $preprocessedQuery
    ): PreprocessedQueryInterface {
        if ($preprocessedQuery->wasPreprocessed()) {
            return $preprocessedQuery;
        }

        $key                        = $preprocessedQuery->getUniqueKey();
        $exists                     = $key === '' ? null : $this->preprocessedQueryStorage?->findPreprocessedQuery($key);

        if ($exists instanceof PreprocessedQueryInterface && $exists->wasPreprocessed()) {
            return $exists;
        }

        $this->bindPreprocessedQuery($preprocessedQuery);

        if ($key !== '') {
            $this->preprocessedQueryStorage?->storePreprocessedQuery($preprocessedQuery);
        }

        return $preprocessedQuery;
    }

    /**
     * Preprocesses a new query from the provider and binds it to its executor; a query that fails to preprocess is
     * dropped with its executor. The query is normalised without the caller's context: every call shares it, and the
     * transaction of a call applies only to its run. A run that overlaps another run of the same query, from another
     * coroutine, gets a query of its own from the provider: the executor holds the transaction of one run.
     *
     * @throws QueryException when the query builds an execution plan or is a prepared statement
     */
    protected function bindPreprocessedQuery(PreprocessedQueryInterface $preprocessedQuery): void
    {
        $query                      = $preprocessedQuery->newQuery();
        $executor                   = $this->defineExecutor($query);

        try {
            $this->preprocessForReuse($query, $executor);
        } catch (\Throwable $throwable) {
            $executor->dispose();
            throw $throwable;
        }

        $run                        = function (?TransactionInterface $transaction) use ($executor, $query, $preprocessedQuery) {
            if ($executor->isExecuting()) {
                return $this->executeAql($preprocessedQuery->newQuery(), new AdditionalOptions([], $transaction));
            }

            return $executor->executePreprocessed($query, $transaction);
        };

        $preprocessedQuery->defineExecutor($run, $query);
    }

    /**
     * @phpstan-assert PreprocessedQueryExecutorInterface $executor
     *
     * @throws QueryException
     */
    protected function preprocessForReuse(BasicQueryInterface $query, QueryExecutorInterface $executor): void
    {
        if (false === $executor instanceof PreprocessedQueryExecutorInterface) {
            throw new QueryException([
                'query'             => $query,
                'executor'          => $executor::class,
                'template'          => 'The executor {executor} cannot run a preprocessed query',
            ]);
        }

        if ($query->isOption(QueryInterface::PREPARING)) {
            throw new QueryException([
                'query'             => $query,
                'template'          => 'A prepared statement belongs to one connection and cannot be preprocessed',
            ]);
        }

        $executor->preprocessing($query);

        // A plan changes its queries while it runs (a result reader adds the keys it read), so it cannot run twice.
        if ($executor instanceof QueryExecutorWithPlanInterface && $executor->findExecutionPlan() !== null) {
            throw new QueryException([
                'query'             => $query,
                'template'          => 'A query that builds an execution plan cannot be preprocessed',
            ]);
        }
    }

    /**
     * @throws QueryException
     */
    protected function defineEntityNameByQuery(BasicQueryInterface $query): string
    {
        $entityName                 = $query->getMainEntityName();

        if ($entityName !== '') {
            return $entityName;
        }

        if ($query instanceof QueryInterface) {
            $entityName             = $query->getFrom()?->getSubject()->getSubjectName();
        }

        if ($entityName !== null && $entityName !== '' && $entityName !== '0') {
            return $entityName;
        }

        throw new QueryException([
            'template'              => 'Can not define entity name by query',
            'query'                 => $query,
        ]);
    }

    /**
     *
     * @throws QueryException
     * @throws UnknownQueryAction
     */
    protected function defineDefaultExecutor(BasicQueryInterface $basicQuery, ?EntityInterface $entity = null): QueryExecutorInterface
    {
        // First try to get executor from storage
        if ($entity !== null) {
            $executor               = $this->defineExecutorByStorage($basicQuery, $entity);

            if ($executor !== null) {
                return $executor;
            }
        }

        // Second, try to define executor from global config
        $executor                   = $this->queryExecutorFactory?->resolveQueryExecutor($basicQuery, $entity);

        if ($executor === null) {
            throw new QueryException([
                'template'          => 'Can not define executor for query',
                'query'             => $basicQuery,
            ]);
        }

        return $executor;
    }

    protected function defineExecutorByStorage(BasicQueryInterface $basicQuery, EntityInterface $entity): ?QueryExecutorInterface
    {
        $storage                    = $this->storageCollection->findStorage($entity->getStorageName());

        if ($storage instanceof QueryExecutorResolverInterface) {
            return $storage->resolveQueryExecutor($basicQuery, $entity);
        }

        return null;
    }
}
