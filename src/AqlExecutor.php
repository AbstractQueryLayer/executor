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
use IfCastle\AQL\Executor\Preprocessing\PreprocessedQueryInterface;
use IfCastle\AQL\Executor\Preprocessing\PreprocessedQueryStorageInterface;
use IfCastle\AQL\Result\InsertUpdateResultInterface;
use IfCastle\AQL\Result\ResultInterface;
use IfCastle\AQL\Result\TupleInterface;
use IfCastle\AQL\Storage\StorageCollectionInterface;

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
            return $this->resolvePreprocessedQuery($query, $executionContext)->executeQuery();
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
     * @throws QueryException
     */
    protected function resolvePreprocessedQuery(
        PreprocessedQueryInterface                                $preprocessedQuery,
        ExecutionContextInterface|AdditionalHandlerAwareInterface|null $executionContext = null
    ): PreprocessedQueryInterface {
        if ($preprocessedQuery->wasPreprocessed()) {
            return $preprocessedQuery;
        }

        $exists                 = $this->preprocessedQueryStorage?->findPreprocessedQuery($preprocessedQuery->getUniqueKey());

        if ($exists instanceof PreprocessedQueryInterface) {
            return $exists;
        }

        $this->preprocessedQueryStorage?->storePreprocessedQuery($preprocessedQuery);

        $this->defineExecutor($preprocessedQuery->getQuery())->preprocessing($preprocessedQuery->getQuery(), $executionContext);

        return $preprocessedQuery;
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
