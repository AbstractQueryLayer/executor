<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Dsl\Node\ChildNodeMutableInterface;
use IfCastle\AQL\Dsl\Node\Exceptions\NodeException;
use IfCastle\AQL\Dsl\Node\NodeHelper;
use IfCastle\AQL\Dsl\QueryOption;
use IfCastle\AQL\Dsl\QueryOptionInterface;
use IfCastle\AQL\Dsl\Relation\RelationDirection;
use IfCastle\AQL\Dsl\Relation\RelationInterface;
use IfCastle\AQL\Dsl\Sql\Column\Column;
use IfCastle\AQL\Dsl\Sql\Column\ColumnInterface;
use IfCastle\AQL\Dsl\Sql\Conditions\ConditionsInterface;
use IfCastle\AQL\Dsl\Sql\Constant\ConstantInterface;
use IfCastle\AQL\Dsl\Sql\FunctionReference\FunctionReferenceInterface;
use IfCastle\AQL\Dsl\Sql\Helpers\JoinHelper;
use IfCastle\AQL\Dsl\Sql\Parameter\ParameterInterface;
use IfCastle\AQL\Dsl\Sql\Query\Copy;
use IfCastle\AQL\Dsl\Sql\Query\Delete;
use IfCastle\AQL\Dsl\Sql\Query\Exceptions\TransformationException;
use IfCastle\AQL\Dsl\Sql\Query\Expression\From;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Join;
use IfCastle\AQL\Dsl\Sql\Query\Expression\JoinInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Operation\OperationInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Subject;
use IfCastle\AQL\Dsl\Sql\Query\Expression\SubjectInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\WhereEntity;
use IfCastle\AQL\Dsl\Sql\Query\Insert;
use IfCastle\AQL\Dsl\Sql\Query\QueryInterface;
use IfCastle\AQL\Dsl\Sql\Query\Select;
use IfCastle\AQL\Dsl\Sql\Query\Subquery;
use IfCastle\AQL\Dsl\Sql\Query\SubqueryInterface;
use IfCastle\AQL\Dsl\Sql\Query\Update;
use IfCastle\AQL\Dsl\Sql\Tuple\Tuple;
use IfCastle\AQL\Dsl\Sql\Tuple\TupleColumn;
use IfCastle\AQL\Dsl\Sql\Tuple\TupleColumnInterface;
use IfCastle\AQL\Entity\EntityInterface;
use IfCastle\AQL\Entity\Exceptions\EntityDescriptorException;
use IfCastle\AQL\Entity\Functions\FunctionInterface;
use IfCastle\AQL\Entity\Relation\DirectRelationInterface;
use IfCastle\AQL\Entity\Relation\IndirectRelationInterface;
use IfCastle\AQL\Executor\Context\FunctionResolverInterface;
use IfCastle\AQL\Executor\Context\NodeContextInterface;
use IfCastle\AQL\Executor\Context\PropertyContext;
use IfCastle\AQL\Executor\Exceptions\FunctionNotFound;
use IfCastle\AQL\Executor\Exceptions\QueryException;
use IfCastle\AQL\Executor\Helpers\ConsistencyHelper;
use IfCastle\AQL\Executor\Plan\CommandAwareInterface;
use IfCastle\AQL\Executor\Plan\ExecutionContextInterface;
use IfCastle\AQL\Executor\Plan\ExecutionPlanInterface;
use IfCastle\AQL\Executor\Plan\NodeWithCommand;
use IfCastle\AQL\Executor\Plan\SqlQueryCommand;
use IfCastle\AQL\Executor\Plan\SqlQueryCommandInterface;
use IfCastle\AQL\Executor\Resolver\SqlRelationResolverForDelete;
use IfCastle\AQL\Executor\Resolver\SqlRelationResolverForInsert;
use IfCastle\AQL\Executor\Resolver\SqlRelationResolverForSelect;
use IfCastle\AQL\Executor\Resolver\SqlRelationResolverForUpdate;
use IfCastle\AQL\Executor\Transformer\WhereEntityTransformer;
use IfCastle\AQL\Result\InsertUpdateResultInterface;
use IfCastle\AQL\Result\InsertUpdateResultSetterInterface;
use IfCastle\AQL\Result\ResultInterface;
use IfCastle\AQL\Result\ResultNull;
use IfCastle\AQL\Result\TupleInterface;
use IfCastle\AQL\Storage\AqlStorageInterface;
use IfCastle\AQL\Storage\Exceptions\RecoverableException;
use IfCastle\AQL\Storage\SqlStatementFactoryInterface;
use IfCastle\AQL\Storage\SqlStorageInterface;
use IfCastle\AQL\Storage\StorageCollectionInterface;
use IfCastle\Exceptions\RequiredValueEmpty;
use IfCastle\Exceptions\UnexpectedValueType;

/**
 * Executive and SQL query converter.
 *
 */
abstract class QueryExecutorAbstract extends QueryExecutorBasicAbstract implements
    QueryHandlerInterface,
    QueryPostHandlerInterface,
    ColumnHandlerInterface,
    ParameterHandlerInterface,
    FunctionHandlerInterface,
    OptionHandlerInterface,
    SubjectHandlerInterface,
    FunctionResolverInterface
{
    protected bool|null $usePreparedMode = null;

    protected int $placeholderCounter = -1;

    /**
     *
     * @throws QueryException
     */
    #[\Override]
    public function handleQuery(BasicQueryInterface $query, NodeContextInterface $context): void
    {
        $entityName                 = $query->getMainEntityName();

        if ($entityName === '') {
            throw new QueryException([
                'template'          => 'Main entity name is empty but required',
                'query'             => $query,
            ]);
        }

        if ($this->scopeProcessor instanceof QueryHandlerInterface) {
            $this->scopeProcessor->handleQuery($query, $context);
        }

        if ($query->getSubstitution() !== null) {
            return;
        }

        if ($this->additionalHandler instanceof QueryHandlerInterface) {
            $this->additionalHandler->handleQuery($query, $context);
        }

        if ($query->getSubstitution() !== null) {
            return;
        }

        $entity                     = $context->getEntity($entityName);

        if ($entity instanceof QueryHandlerInterface) {
            $entity->handleQuery($query, $context);
        }

        if ($query->getSubstitution() !== null) {
            return;
        }

        if ($query instanceof SubqueryInterface) {
            $this->handleSubquery($query, $context);
            return;
        }

        if ($query->getQueryStorage() === null) {
            $query->setQueryStorage($entity->getStorageName() ?? StorageCollectionInterface::STORAGE_MAIN);
        }

        $this->handleQueryAfter($query, $context);
    }

    protected function handleQueryAfter(BasicQueryInterface $query, NodeContextInterface $context): void {}


    #[\Override]
    public function postHandleQuery(BasicQueryInterface $query, NodeContextInterface $context): void
    {
        if ($this->additionalHandler instanceof QueryPostHandlerInterface) {
            $this->additionalHandler->postHandleQuery($query, $context);
        }

        if ($query->getSubstitution() !== null) {
            return;
        }

        // Call post-handle for query entity
        $entity                     = $context->getEntity($query->getMainEntityName());

        if ($entity instanceof QueryPostHandlerInterface) {
            $entity->postHandleQuery($query, $context);
        }

        if ($query->getSubstitution() !== null) {
            return;
        }

        $this->postHandleQueryAfter($query, $context);
    }

    protected function postHandleQueryAfter(BasicQueryInterface $query, NodeContextInterface $context): void {}

    /**
     * @throws EntityDescriptorException
     * @throws QueryException
     */
    #[\Override]
    public function handleJoin(JoinInterface $join, NodeContextInterface $context): void
    {
        if ($this->scopeProcessor instanceof SubjectHandlerInterface) {
            $this->scopeProcessor->handleJoin($join, $context);
        }

        if ($join->getSubstitution() !== null) {
            return;
        }

        // Try to detect entity by subject name or alias
        if ($join->getSubject()->getSubjectName() !== '') {
            $entity                 = $context->getEntity($join->getSubject()->getSubjectName());
        } else {
            $entity                 = $context->getEntity($join->getSubject()->getNameOrAlias());
        }

        if ($entity instanceof SubjectHandlerInterface) {
            $entity->handleJoin($join, $context);
        }

        if ($this->additionalHandler instanceof SubjectHandlerInterface) {
            $this->additionalHandler->handleJoin($join, $context);
        }

        if ($join->getSubstitution() !== null) {
            return;
        }

        //
        // Join processing is performed in two stages:
        // 1. Define relation - try to find relation between entities
        // 2. Handling relation - try to handle a separate query
        //
        if (false === ($join instanceof From) && $join->getRelation() === null) {
            $this->defineJoinRelation($join, $context, $entity);
        }

        // If the structure has been changed, stop any analysis
        if ($join->getSubstitution() !== null) {
            return;
        }

        $relation                   = $join->getRelation();

        if (true === $relation?->isNotTransformed()) {
            $this->handleJoinRelation($relation, $join, $context, $entity);
        }
    }

    /**
     * @throws EntityDescriptorException
     * @throws \IfCastle\AQL\Dsl\Node\Exceptions\NodeException
     * @throws QueryException
     */
    #[\Override]
    public function handleColumn(ColumnInterface $column, NodeContextInterface $context): void
    {
        $entityName                 = $column->getEntityName();

        if ($entityName !== null) {
            $entity                 = $context->getEntity($entityName);
        } else {
            $entity                 = $context->getCurrentEntity();
            $column->setEntityName($entity->getEntityName());
        }

        //
        // If the relationship between entities is not consistent,
        // we must create a separate query.
        // This condition immediately stops the column processing!
        //
        if (false === $entity->isConsistentRelationWith($context->getCurrentEntity())) {
            $this->defineSeparateQueryForColumn($column, $context, $entity);
            return;
        }

        if ($this->scopeProcessor instanceof ColumnHandlerInterface) {
            $this->scopeProcessor->handleColumn($column, $context);
        }

        if ($column->getSubstitution() !== null) {
            return;
        }

        if ($this->additionalHandler instanceof ColumnHandlerInterface) {
            $this->additionalHandler->handleColumn($column, $context);
        }

        if ($column->getSubstitution() !== null) {
            return;
        }

        if ($entity instanceof ColumnHandlerInterface) {
            $entity->handleColumn($column, $context);
        }

        if ($column->getSubstitution() !== null) {
            return;
        }

        $this->handleProperty($column, $context, $entity);
    }

    #[\Override]
    public function handleParameter(ConstantInterface $constant, NodeContextInterface $context): void
    {
        // Only parameters are added to the query parameters list
        if ($constant instanceof ParameterInterface) {
            $context->getMainSqlQuery()->addQueryParameter($constant);

            if ($constant->isValueList()) {
                $context->getMainSqlQuery()->getQueryOptions()
                    ->addOption(new QueryOption(QueryInterface::PREPARING, false, true))
                    ->addOption(new QueryOption(QueryInterface::NO_STRINGABLE, true, true));
            }

            $this->usePreparedMode = false;
        }

        if ($this->usePreparedMode === null) {
            $this->definePreparedMode();
        }

        if ($this->usePreparedMode === false) {
            return;
        }

        if ($constant->getParentNode()?->getSubstitution() !== null) {
            return;
        }

        if ($constant->getPlaceholder() === null) {
            $constant->asPlaceholder($this->generatePlaceholderName());
        }

        // All constant values are set in the query as bind parameters
        if (false === $constant instanceof ParameterInterface) {
            $context->getSqlStatement()?->bindParameter($constant);
        }
    }

    protected function generatePlaceholderName(): string
    {
        return ':p' . ++$this->placeholderCounter;
    }

    protected function definePreparedMode(): void
    {
        $query                      = $this->queryContext->get()->getBasicQuery();
        $storage                    = $this->storageCollection->findStorage($query->getQueryStorage());

        if ($query->isOption(QueryInterface::PREPARING) && $storage instanceof SqlStatementFactoryInterface) {
            $this->usePreparedMode   = true;
        } else {
            $this->usePreparedMode   = false;
        }
    }

    #[\Override]
    public function handleOption(QueryOptionInterface $queryOption, NodeContextInterface $context): void
    {
        if ($this->scopeProcessor instanceof OptionHandlerInterface) {
            $this->scopeProcessor->handleOption($queryOption, $context);
        }

        if ($queryOption->getSubstitution() !== null) {
            return;
        }

        if ($this->additionalHandler instanceof OptionHandlerInterface) {
            $this->additionalHandler->handleOption($queryOption, $context);
        }

        if ($queryOption->getSubstitution() !== null) {
            return;
        }

        $entity                     = $context->getMainEntity();

        if ($entity instanceof OptionHandlerInterface) {
            $entity->handleOption($queryOption, $context);
        }
    }

    #[\Override]
    public function handleSubject(SubjectInterface $subject, NodeContextInterface $context): void
    {
        if ($subject->getSubjectName() === '') {
            $entity                 = $context->getCurrentEntity();
        } else {
            $entity                 = $context->getEntity($subject->getSubjectName());
        }

        if ($subject->getResolvedName() === null || $subject->getResolvedName() === '') {
            $subject->setResolvedName($entity->getSubject());
        }

        if ($this->scopeProcessor instanceof SubjectHandlerInterface) {
            $this->scopeProcessor->handleSubject($subject, $context);
        }

        if ($subject->getSubstitution() !== null) {
            return;
        }

        if ($this->additionalHandler instanceof SubjectHandlerInterface) {
            $this->additionalHandler->handleSubject($subject, $context);
        }

        if ($subject->getSubstitution() !== null) {
            return;
        }

        // No need alias for INSERT
        if ($context->getBasicQuery()->getResolvedAction() === QueryInterface::ACTION_INSERT) {
            return;
        }

        if ($subject->getSubjectAlias() === '') {
            $subject->setSubjectAlias($context->defineAlias($entity));
        }
    }

    /**
     * @throws FunctionNotFound
     * @throws QueryException
     */
    #[\Override]
    public function handleFunction(FunctionReferenceInterface $function, NodeContextInterface $context): void
    {
        if ($this->scopeProcessor instanceof FunctionHandlerInterface) {
            $this->scopeProcessor->handleFunction($function, $context);
        }

        if ($function->getSubstitution() !== null) {
            return;
        }

        if ($this->additionalHandler instanceof FunctionHandlerInterface) {
            $this->additionalHandler->handleFunction($function, $context);
        }

        if ($function->getSubstitution() !== null) {
            return;
        }

        $entity                     = $context->getEntity(
            $function->getEntityName() ?? $context->getCurrentEntity()->getEntityName()
        );

        if ($entity instanceof FunctionHandlerInterface) {
            $entity->handleFunction($function, $context);
        }

        if ($function->getSubstitution() !== null) {
            return;
        }

        // If function is not virtual we try to search it in storage
        if ($function->isNotVirtual()) {
            $storage                    = $this->storageCollection->findStorage($context->getCurrentQuery()->getQueryStorage());

            // If storage is found and supports functions, we try to handle it
            if ($storage instanceof FunctionHandlerInterface) {
                $storage->handleFunction($function, $context);
            }

            if ($function->getSubstitution() !== null) {
                return;
            }
        }

        // At least we try to find function in global storage
        if (false === $function->isGlobal() || $this->functionStorage === null) {
            throw new FunctionNotFound($function->getFunctionName());
        }

        $this->functionStorage?->getFunction($function->getFunctionName())->handleFunction($function, $context);

        if ($function->getSubstitution() === null) {
            throw new QueryException([
                'query'             => $context->getBasicQuery(),
                'function'          => $function->getFunctionName(),
                'entity'            => $function->getEntityName() ?? '',
                'template'          => 'The function {entity}.{function} is not handled properly (node is not substituted)',
            ]);
        }
    }

    #[\Override]
    public function resolveFunction(FunctionReferenceInterface|string $functionReference, ?NodeContextInterface $context = null): ?FunctionInterface
    {
        $functionName               = $functionReference instanceof FunctionReferenceInterface
            ? $functionReference->getFunctionName()
            : $functionReference;

        return $this->functionStorage?->findFunction($functionName);
    }

    /**
     * @throws EntityDescriptorException
     * @throws \IfCastle\AQL\Dsl\Node\Exceptions\NodeException
     * @throws QueryException
     */
    protected function handleProperty(ColumnInterface $column, NodeContextInterface $context, EntityInterface $entity): void
    {
        $property                   = $entity->getProperty($column->getColumnName());

        $inheritedFrom              = $property->getInheritedFrom();

        if ($inheritedFrom !== null) {
            //
            // since the handler is called after the
            // Subject has already been evaluated, we must correct its evaluation
            //
            $inheritedEntity        = $context->getEntity($inheritedFrom);

            $column
                ->setEntityName($inheritedFrom)
                ->setSubject($inheritedEntity->getSubject())
                ->setSubjectAlias($context->defineAlias($inheritedFrom));

            $this->handleProperty($column, $context, $inheritedEntity);
            return;
        }

        $query                      = $context->getCurrentSqlQuery();

        //
        // Correct subject value for insert queries
        //
        if ($query->getResolvedAction() === QueryInterface::ACTION_INSERT) {
            $column->setSubject('')->setSubjectAlias('');
        } else {
            //
            // and others type
            //
            $column
                ->setSubject($entity->getSubject())
                ->setSubjectAlias($context->defineAlias($entity, $column->isForeign()));
        }

        $property->handle(new PropertyContext($column, $entity, $context));

        if ($column->getSubstitution() !== null) {
            return;
        }

        $currentEntity              = $context->getCurrentEntity();

        // If current property from this Entity nothing to do
        if ($currentEntity->getEntityName() === $entity->getEntityName()) {
            return;
        }

        //
        // If the processing of a property takes place inside a relationship or the column is "Foreign",
        // there is no need to add tables
        // Or FieldRef was substituted to another expression
        //
        if ($column->isForeign() || $column->getSubstitution() !== null || NodeHelper::inRelation($column)) {
            return;
        }

        //
        // Special Cases:
        // 1. INSERT FROM SELECT
        // 2. UPDATE with derived tables
        // 3. DELETE with several tables
        //
        if (NodeHelper::inFromSelect($column)) {
            // For INSERT FROM SELECT any dependents not needed
            return;
        }

        /**
         * Goal: create join by relation.
         */
        $relation                   = $currentEntity->resolveRelation($entity);

        if ($relation->isNotConsistentRelations()) {
            throw new QueryException([
                'template'          => 'The relationship between entities {entity1} and {entity2} is not consistent.',
                'entity1'           => $currentEntity->getEntityName(),
                'entity2'           => $entity->getEntityName(),
            ]);
        }

        //
        // attaches entity to the query
        //

        if ($query->getFrom()->isJoinExists($entity->getEntityName(), $currentEntity->getEntityName())) {
            return;
        }

        $joinType = NodeHelper::inTuple($column) ? $this->relationToJoinForTuple($relation) : $this->relationToJoin($relation);

        // Convert result AGGREGATION-expressions to Subqueries
        if ($joinType === JoinInterface::SUBQUERY) {

            if (NodeHelper::inTuple($column)) {
                $this->columnToSubquery($column, $entity, $context);
            } elseif (NodeHelper::inWhere($column)) {
                $this->filterToSubquery($column, $entity, $query, $context);
            }

            return;
        }

        $parentJoin                 = $query->getFrom()->findJoin($currentEntity->getEntityName(), true);

        if ($parentJoin === null && $currentEntity->getEntityName() === $query->getMainEntityName()) {
            $parentJoin             = $query->getFrom();
        }

        $thisAlias                  = $context->defineAlias($entity);

        $parentJoin->addJoin(
            new Join(
                $joinType,
                new Subject($entity->getEntityName(), $entity->getSubject(), $thisAlias),
                $relation
            ),
            $thisAlias
        );
    }

    /**
     * Convert column from result to subquery for aggregations.
     * @throws \IfCastle\AQL\Dsl\Node\Exceptions\NodeException
     */
    protected function columnToSubquery(ColumnInterface $column, EntityInterface $entity, NodeContextInterface $context): void
    {
        $subquery               = new Subquery(
            $entity->getEntityName(), new Tuple(new TupleColumn(new Column($column->getColumnName())))
        );

        $column->setSubstitution($subquery);

        // Auto define alias
        $column                 = $context->getCurrentNode();

        if ($column instanceof ColumnInterface && $column->getSubjectAlias() === '') {
            $column->setSubjectAlias($column->getColumnName());
        }
    }

    /**
     * @throws QueryException
     */
    protected function filterToSubquery(ColumnInterface $column, EntityInterface $entity, QueryInterface $query, NodeContextInterface $context): void
    {
        // Forget about normalization column node
        // it will be normalized in the next step inside the subquery
        $column->needTransform();

        $conditions             = NodeHelper::findFirstChildOfWhereNode($column);

        if (false === $conditions instanceof OperationInterface && false === $conditions instanceof ConditionsInterface) {
            throw new QueryException([
                'template'      => 'The first child of WHERE node should be an instance of '
                                   . 'OperationInterface|ConditionsInterface inside WHERE clause '
                                   . ' for Subquery expression. '
                                   . ' Required by column {column} in expression {aql}',
                'column'        => $column->getAql(),
                'aql'           => $conditions?->getAql(),
            ]);
        }

        // If where entity node already exists, we must use it
        $whereEntity                = WhereEntity::findWhereEntity(
            $entity->getEntityName(), ...$query->getWhere()->getChildNodes()
        );

        if ($whereEntity !== null) {
            throw new TransformationException([
                'template'          => 'The specified column {entity}.{column} in conditions {conditions} '
                                   . 'cannot be used for the entity {entity} along '
                                   . 'with conditions {whereEntity} as it lacks logical '
                                   . 'consistency or the current code is unable to handle it.',
                'column'            => $column->getColumnName(),
                'conditions'        => $conditions->getAql(),
                'entity'            => $entity->getEntityName(),
                'whereEntity'       => $whereEntity->getAql(),
            ]);
        }

        $whereEntity                = new WhereEntity($entity->getEntityName());
        $whereEntity->conditions()->add((clone $conditions)->needTransform());
        $conditions->setSubstitution($whereEntity);
        WhereEntityTransformer::moveOperationsToConditions($whereEntity, $query->getWhere()->getChildNodes());
    }

    /**
     * The method creates a separate request to the entity if necessary
     * and executes the normalization handler after the request is created.
     *
     * $handler has prototype: function(SqlQueryInterface $query): void
     */
    public function defineSeparateQueryAndNormalizeWith(
        NodeContextInterface $context,
        EntityInterface      $entity,
        callable             $handler
    ): void {
        JoinHelper::findOrCreateJoinByEntityName(
            $context->getCurrentSqlQuery(), $entity->getEntityName()
        )->afterTransformation(static function (?NodeContextInterface $context = null, ?JoinInterface $join = null) use ($handler) {

            if ($join === null) {
                return;
            }

            $command                = $join->getSubstitution();

            if (false === $command instanceof CommandAwareInterface) {
                return;
            }

            $command                = $command->getCommand();

            if (false === $command instanceof SqlQueryCommandInterface) {
                // ignore other types of commands
                return;
            }

            $handler($command->getContainedSqlQuery());
        });
    }

    /**
     * @throws QueryException
     */
    public function defineSeparateQueryForColumn(
        ColumnInterface      $column,
        NodeContextInterface $context,
        EntityInterface      $entity,
    ): void {
        if (NodeHelper::inCteOrSubquery($column)) {
            throw new QueryException([
                'template'          => 'Generation of separate queries is not possible in the context '
                                       . 'of CTE expressions or subqueries. Column {expression} in query {query}',
                'expression'        => $column->getAql(),
                'query'             => $context->getCurrentSqlQuery()->getAql(),
            ]);
        }

        /**
         * Inconsistent queries in the current implementation have an important limitation.
         * The query: SELECT * FROM entity1, entity2 WHERE entity1.filter = 1 AND entity2.filter2 = 2
         * will be executed as follows:
         *
         * First, all entities will be retrieved for
         * SELECT * FROM entity1 WHERE entity1.filter = 1
         * Then for
         * SELECT * FROM entity2 WHERE entity2.filter = 2 AND (Relations)
         * In other words, this is no longer a cartesian product operation!
         */
        $targetNode                 = NodeHelper::findBasicExpressionByNode($column) ?? throw new TransformationException([
            'template'              => 'The expression is not found for column {column} in the query {query}',
            'query'                 => $context->getCurrentSqlQuery()->getAql(),
            'column'                => $column->getColumnName(),
        ]);

        $basicNode                  = $targetNode->getParentNode();

        if ($basicNode === null) {
            throw new QueryException([
                'template'          => 'The basic node is not found in the query {query} for {targetNode}',
                'query'             => $context->getCurrentSqlQuery()->getAql(),
                'targetNode'        => $targetNode->getAql(),
            ]);
        }

        if (false === $basicNode instanceof ChildNodeMutableInterface) {
            throw new QueryException([
                'template'          => 'The basic node type {node} does not support '
                                       . 'adding child nodes (ChildNodeMutableInterface): {nodeAql} in {query}',
                'node'              => $basicNode->getNodeName(),
                'nodeAql'           => $basicNode->getAql(),
                'query'             => $context->getCurrentSqlQuery()->getAql(),
            ]);
        }

        ConsistencyHelper::checkNode($targetNode, $entity, $context);

        $basicNodeName              = $basicNode->getNodeName();
        $targetNodeCopy             = clone $targetNode;

        if ($targetNode instanceof TupleColumnInterface) {
            $targetNode->markAsPlaceholder();
        } else {
            $targetNode->substituteToNullNode();
        }

        $this->defineSeparateQueryAndNormalizeWith($context, $entity, static fn(QueryInterface $query) =>
            $query->addChildrenToBasicNode($basicNodeName, $targetNodeCopy)
        );
    }

    /**
     *
     * @throws  NodeException
     * @throws  QueryException
     * @throws  RequiredValueEmpty|UnexpectedValueType
     */
    public function createSeparateQueryForJoin(
        RelationInterface $relation,
        JoinInterface $join,
        NodeContextInterface $context,
        EntityInterface $toEntity
    ): void {
        if ($relation instanceof DirectRelationInterface === false) {
            throw new QueryException([
                'query'             => $context->getBasicQuery(),
                'relation'          => \get_debug_type($relation),
                'template'          => 'The current Query executor can only handle relation like DirectRelationI, got {relation}',
            ]);
        }

        //
        // 1. Create new Query from Join
        //
        $fromQuery                  = $context->getCurrentSqlQuery();
        $queryAction                = $fromQuery->getResolvedAction();

        $toQuery                    = match ($queryAction) {
            QueryInterface::ACTION_COUNT,
            QueryInterface::ACTION_SELECT  => new Select($toEntity->getEntityName()),
            QueryInterface::ACTION_INSERT,
            QueryInterface::ACTION_REPLACE => new Insert($toEntity->getEntityName()),
            QueryInterface::ACTION_COPY    => new Copy($toEntity->getEntityName()),
            QueryInterface::ACTION_UPDATE  => new Update($toEntity->getEntityName()),
            QueryInterface::ACTION_DELETE  => new Delete($toEntity->getEntityName()),

            default                        => throw new QueryException([
                'query'             => $fromQuery,
                'action'            => $fromQuery->getResolvedAction(),
                'template'          => 'Unknown parent query action {action} to create separate Query for Join',
            ]),
        };

        $executionPlan              = $this->getExecutionPlan();
        $fromCommand                = $context->getQueryCommand();

        if ($fromCommand instanceof SqlQueryCommandInterface === false) {
            throw new QueryException([
                'query'             => $fromQuery,
                'command'           => \get_debug_type($fromCommand),
                'template'          => 'The current Query executor can only process SQL queries and commands, got {command}',
            ]);
        }

        //
        // The Delete\Update query requires the creation of a normalizing Select-query,
        // which will first get the identifiers of the entities to be deleted, and then delete them.
        //

        $normalizingPlan            = null;

        if ($fromQuery->isInsertUpdate()) {
            $this->defineNormalizingPlan($context);
            $normalizingPlan        = $context->getQueryExecutor()->getNormalizingPlan();
        }

        switch ($queryAction) {
            case QueryInterface::ACTION_SELECT:

                $toCommand          = new SqlQueryCommand(
                    $this->relationToSide($relation),
                    fn() => $this->aqlExecutor->executeAql($toQuery), $toQuery
                );

                $executionPlan->addCommand($toCommand);
                SqlRelationResolverForSelect::resolve($fromCommand, $relation, $toCommand);

                break;

            case QueryInterface::ACTION_INSERT:
            case QueryInterface::ACTION_REPLACE:
            case QueryInterface::ACTION_COPY:

                $toCommand          = new SqlQueryCommand(
                    $this->relationToSide($relation),
                    fn() => $this->aqlExecutor->executeAql($toQuery), $toQuery
                );

                $executionPlan->addCommand($toCommand);
                SqlRelationResolverForInsert::resolve($context, $toCommand, $relation, $fromCommand);
                break;

            case QueryInterface::ACTION_UPDATE:

                //
                // For UPDATE DELETE queries, we must use a normalization SELECT query to determine the dependencies.
                // So the code logic is:
                // 1. Create a SELECT query that will receive dependent data
                // 2. Associate it with the current query
                //

                // Build normalizing select
                $fromSelectCommand  = $normalizingPlan->defineNormalizingSqlCommand($fromQuery);
                $toCommand          = new SqlQueryCommand(
                    $this->relationToSide($relation),
                    fn() => $this->aqlExecutor->executeAql($toQuery), $toQuery
                );

                $executionPlan->addCommand($toCommand);

                SqlRelationResolverForUpdate::resolve($toCommand, $relation, $fromSelectCommand);
                break;

            case QueryInterface::ACTION_DELETE:

                $fromSelectCommand  = $normalizingPlan->defineNormalizingSqlCommand($fromQuery);

                $side               = $this->relationToSide($relation, true);

                $toCommand          = new SqlQueryCommand(
                    $side,
                    fn() => $this->aqlExecutor->executeAql($toQuery), $toQuery
                );

                // For Delete operations all dependencies
                // should be executed in reverse order
                $executionPlan->addCommand(command: $toCommand, toStart: $side === ExecutionPlanInterface::RIGHT);

                SqlRelationResolverForDelete::resolve($toCommand, $relation, $fromSelectCommand);
                break;

            default:

                throw new QueryException([
                    'query'             => $fromQuery,
                    'action'            => $fromQuery->getResolvedAction(),
                    'template'          => 'Unknown parent query action {action} to create separate Query for Join',
                ]);
        }

        $join->setSubstitution(new NodeWithCommand($toCommand));
    }

    /**
     * @throws QueryException
     * @throws RecoverableException
     */
    protected function executeTransformedQuery(BasicQueryInterface $query): ResultInterface
    {
        if ($query->isNotTransformed()) {
            throw new QueryException([
                'query'             => $query,
                'template'          => 'The query is not normalized',
            ]);
        }

        if ($query->getQueryStorage() === null) {
            throw new QueryException([
                'query'             => $query,
                'template'          => 'Query storage undefined',
            ]);
        }

        $storage                    = $this->storageCollection->findStorage($query->getQueryStorage());

        if ($storage === null) {
            throw new QueryException([
                'query'             => $query,
                'template'          => 'The query storage {storage} is not found',
                'storage'           => $query->getQueryStorage(),
            ]);
        }

        // Support for AQL queries
        if ($storage instanceof AqlStorageInterface) {

            $result                     = $storage->executeAql($query, $this->queryContext);

            // Save hidden columns count
            if ($result instanceof TupleInterface && $query instanceof QueryInterface) {
                $result->setHiddenColumns(\count($query->getTuple()?->getHiddenColumns() ?? []));
            }

            return $result;
        }

        // Support for Pure SQL queries
        if ($storage instanceof SqlStorageInterface === false) {
            throw new QueryException([
                'query'             => $query,
                'template'          => 'The storage {storage} ({storageClass}) does not support either the SqlStorageInterface '
                                       . 'or the AqlStorageInterface and cannot be used with this query executor {class}.',
                'class'             => static::class,
                'storage'           => $query->getQueryStorage(),
                'storageClass'      => $storage::class,
            ]);
        }

        $statement                  = $this->queryContext->get()->getSqlStatement();

        // Support prepared queries
        if ($statement === null && $query->isOption(QueryInterface::PREPARING) && $storage instanceof SqlStatementFactoryInterface) {

            $sql                    = $query->getResultAsString();

            if ($sql === '') {
                return new ResultNull();
            }

            $statement              = $storage->createStatement($sql, $this->queryContext);
            $this->queryContext->get()->setSqlStatement($statement);
            $parameters             = $this->extractQueryParameters($query);
            $context                = $this->queryContext->get();

            $operation              = static fn() => $storage->executeStatement($statement, $parameters, $context);

        } elseif ($statement !== null) {

            if ($storage instanceof SqlStatementFactoryInterface === false) {
                throw new QueryException([
                    'query'         => $query,
                    'template'      => 'The storage {storage} does not support the SqlStatementFactoryInterface',
                    'storage'       => $query->getQueryStorage(),
                ]);
            }

            $parameters             = $this->extractQueryParameters($query);
            $context                = $this->queryContext->get();
            $operation              = static fn() => $storage->executeStatement($statement, $parameters, $context);
        } else {
            $sql                    = $query->getResultAsString();

            if ($sql === '') {
                return new ResultNull();
            }

            $operation              = static fn(NodeContextInterface $context) => $storage->executeSql($sql, $context);
        }

        $result                     = $this->tryToExecuteTwice($operation, $query);

        // Save hidden columns count
        if ($result instanceof TupleInterface && $query instanceof QueryInterface && $query->isSelect()) {
            $result->setHiddenColumns(\count($query->getTuple()?->getHiddenColumns() ?? []));
        } elseif ($result instanceof InsertUpdateResultSetterInterface && $query instanceof QueryInterface && $query->isInsertUpdate()) {
            $result->setInsertUpdateResult($this->buildInsertUpdateResult($query, $storage, $result));
        }

        return $result;
    }

    /**
     * Try to execute the query twice if catch RecoverableException.
     *
     * @throws RecoverableException
     */
    protected function tryToExecuteTwice(callable $execute, BasicQueryInterface $query): ResultInterface
    {
        //
        // Try to execute the query twice if catch RecoverableException
        //
        try {
            return $execute($this->queryContext?->get());
        } catch (RecoverableException $recoverableException) {

            if ($query->isResolvedAsSelect() && $this->queryContext?->get()?->getTransaction() === null) {
                return $execute($this->queryContext?->get());
            }

            throw $recoverableException;

        }
    }

    /**
     * @throws QueryException
     */
    protected function extractQueryParameters(BasicQueryInterface $query): array
    {
        if (false === $query instanceof QueryInterface) {
            return [];
        }

        $parameters                 = [];

        foreach ($query->getQueryParameters() as $parameter) {
            $placeholder            = $parameter->getPlaceholder();

            if ($placeholder === null) {
                throw new QueryException([
                    'query'         => $query,
                    'template'      => 'The placeholder for the parameter {parameter} {class} is not defined',
                    'class'         => $parameter::class,
                    'parameter'     => $parameter instanceof ParameterInterface ? $parameter->getParameterName() : '',
                ]);
            }

            $parameters[$placeholder] = $parameter->getConstantValue();
        }

        return $parameters;
    }

    protected function buildInsertUpdateResult(QueryInterface $query, SqlStorageInterface $storage, ResultInterface $result): InsertUpdateResultInterface
    {
        return new InsertUpdateResult(
            $this->entityFactory->getEntity($query->getMainEntityName()),
            $query,
            $storage->lastInsertId(),
            $result->affected()
        );
    }

    /**
     * Execute a normalized query with context and save a result to context.
     *
     *
     * @throws   QueryException
     */
    #[\Override]
    protected function executeQueryWithContext(BasicQueryInterface $query, ?ExecutionContextInterface $context = null): ResultInterface
    {
        $result                     = $this->executeTransformedQuery($query);

        $context?->setResult($result);

        return $result;
    }

    /**
     * @throws EntityDescriptorException
     * @throws QueryException
     */
    protected function defineJoinRelation(JoinInterface $join, NodeContextInterface $context, EntityInterface $entity): void
    {
        //
        // 1. Try to define relation with parent entity
        //
        $parentJoin                 = $join->getParentJoin();

        if ($parentJoin === null) {
            throw new QueryException([
                'query'             => $context->getCurrentQuery(),
                'join'              => $join->getSubject()->getSubjectName(),
                'template'          => 'Parent join undefined but required by {join}',
            ]);
        }

        $parentEntity               = $context->getEntity($parentJoin->getSubject()->getSubjectName());

        //
        // Trying to find a relationship from the parent Join entity to current Join entity.
        // So
        // SELECT * FROM parentEntity
        // JOIN current Entity ON (relation from parentEntity -> current)
        //
        // NOT
        // current -> parent!!!
        //
        // The direction of the ratios matters, as does the order in which they are calculated.
        // The entity to which the JOIN of another entity occurs is the first to have the right to form a relationship.
        //
        $relation                   = $parentEntity->resolveRelation($entity);

        if ($relation instanceof IndirectRelationInterface) {
            // Attach to join child relations
            $this->attachToJoinIndirectRelation($relation, $join, $context);
        } else {
            $join->setRelation($relation)->setJoinType($this->relationToJoin($relation));
        }
    }

    /**
     * Convert indirect relations to Joins and attach it to the parent node.
     *
     *
     * @throws EntityDescriptorException
     * @throws QueryException
     */
    protected function attachToJoinIndirectRelation(IndirectRelationInterface $relation, JoinInterface $currentJoin, NodeContextInterface $context): void
    {
        //
        // Case: Entity1 relation to Entity3 over Entity2
        // Means:
        // SELECT * FROM Entity1, Entity2
        // should be converted to
        //
        // SELECT * FROM Entity1
        // INNER JOIN Entity3 ON (Entity3.key3 = Entity1.key1)
        // INNER JOIN Entity2 ON (Entity2.key2 = Entity3.key3)
        //

        // $entitiesPath equal:
        // Entity1 => Entity3 => Entity2
        $entitiesPath               = $relation->getEntitiesPath();
        // Remove first entity because already exist in the current expression
        $fromEntityName             = \array_shift($entitiesPath);
        $firstJoin                  = null;

        foreach ($entitiesPath as $toEntityName) {

            $fromEntity             = $this->entityFactory->getEntity($fromEntityName);
            $toEntity               = $this->entityFactory->getEntity($toEntityName);
            $currentRelation        = $fromEntity->getRelation($toEntityName);

            if ($currentRelation instanceof IndirectRelationInterface) {
                throw new QueryException([
                    'fromEntity'    => $fromEntity->getEntityName(),
                    'toEntity'      => $toEntity->getEntityName(),
                    'template'      => 'The relation between {fromEntity} and {toEntity} '
                        . 'cannot be indirect relation inside another indirect relation',
                ]);
            }

            $join                   = $this->createJoinByRelation($currentRelation, $fromEntity, $toEntity);
            $join->setAlias($context->defineAlias($join->getSubject()->getSubjectName()));

            if ($firstJoin === null) {
                $firstJoin         = $join;
            } else {
                $firstJoin->addJoin($join);
            }

            $fromEntityName         = $toEntityName;
        }

        //
        // Replace expression like: SELECT * FROM Entity1 JOIN Entity2
        // to
        // SELECT * FROM Entity1 JOIN Entity3 JOIN Entity2
        //
        $currentJoin->setSubstitution($firstJoin);
    }

    protected function handleJoinRelation(RelationInterface $relation, JoinInterface $join, NodeContextInterface $context, EntityInterface $entity): void
    {
        if ($relation instanceof IndirectRelationInterface) {
            throw new QueryException([
                'subject'           => $join->getSubject()->getSubjectName(),
                'template'          => 'Relation is not resolved for {subject}: '
                    . 'Indirect relationships must be resolved in the previous step (see defineJoinRelation)',
            ]);
        }

        if ($relation->isConsistentRelations()) {
            return;
        }

        $this->createSeparateQueryForJoin($relation, $join, $context, $entity);
    }

    protected function createJoinByRelation(RelationInterface $relation, EntityInterface $fromEntity, EntityInterface $toEntity): JoinInterface
    {
        //
        // $toEntity should be joined to $fromEntity
        //
        return new Join($this->relationToJoin($relation), $toEntity->getEntityName(), $relation);
    }

    /**
     * @throws  EntityDescriptorException
     */
    protected function relationToJoin(RelationInterface $relation): string
    {
        // All inconsistent relations should be converted to a separated query
        if ($relation->isNotConsistentRelations()) {
            return JoinInterface::QUERY;
        }

        return match ($relation->getRelationType()) {
            RelationInterface::CHILD,
            RelationInterface::BELONGS_TO,
            RelationInterface::INHERITANCE,
            RelationInterface::INHERITED_BY,
            RelationInterface::JOIN      => JoinInterface::INNER,

            RelationInterface::PARENT,
            RelationInterface::OWNS,
            RelationInterface::TREE,
            RelationInterface::COLLECTION,
            RelationInterface::EXTENSION,
            RelationInterface::EXTENDED_BY,
            RelationInterface::REFERENCE => $relation->isRequired() ? JoinInterface::INNER : JoinInterface::LEFT,
            RelationInterface::ASSOCIATION,
            RelationInterface::NESTED    => JoinInterface::SUBQUERY,
            default                      =>
            throw new EntityDescriptorException([
                'template'          => 'Can not determine the joinType for {type} relation',
                'type'              => $relation->getRelationType(),
            ])

        };
    }

    /**
     * @throws  EntityDescriptorException
     */
    protected function relationToJoinForTuple(RelationInterface $relation): string
    {
        // All inconsistent relations should be converted to a separated query
        if ($relation->isNotConsistentRelations()) {
            return JoinInterface::QUERY;
        }

        //
        // Explanation:
        // When someone wants to get a property of an entity as the result of a query, we understand this as a JOIN of entities.
        // This means that the expression:
        // SELECT Entity1.property1, Entity2.property2 is equivalent to
        // SELECT Entity1.property1, Entity2.property2 From Entity1, Entity2
        //
        // The only difference is in the kind of JOIN: Left or Inner.
        //

        return $relation->isRequired() ? JoinInterface::INNER : JoinInterface::LEFT;
    }

    /**
     * The method returns the execution plan side by relationship.
     *
     *
     */
    protected function relationToSide(RelationInterface $relation, bool $isReverse = false): string
    {
        if ($relation->direction() !== RelationDirection::FROM_RIGHT) {
            return $isReverse ? ExecutionPlanInterface::LEFT : ExecutionPlanInterface::RIGHT;
        }

        return $isReverse ? ExecutionPlanInterface::RIGHT : ExecutionPlanInterface::LEFT;
    }

    /**
     * @throws QueryException
     */
    protected function handleSubquery(SubqueryInterface $query, NodeContextInterface $context): void
    {
        throw new QueryException([
            'template'          => 'Subqueries are not supported by the {storage} engine',
            'query'             => $query,
            'storage'           => $query->getQueryStorage(),
        ]);
    }
}
