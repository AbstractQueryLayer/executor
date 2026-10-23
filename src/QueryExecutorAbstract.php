<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Dsl\Node\ChildNodeMutableInterface;
use IfCastle\AQL\Dsl\Node\Exceptions\NodeException;
use IfCastle\AQL\Dsl\Node\NodeHelper;
use IfCastle\AQL\Dsl\Node\NodeInterface;
use IfCastle\AQL\Dsl\Node\NodeRecursiveIterator;
use IfCastle\AQL\Dsl\QueryOptionInterface;
use IfCastle\AQL\Dsl\Relation\RelationDirection;
use IfCastle\AQL\Dsl\Relation\RelationInterface;
use IfCastle\AQL\Dsl\Sql\Column\Column;
use IfCastle\AQL\Dsl\Sql\Column\ColumnInterface;
use IfCastle\AQL\Dsl\Sql\Conditions\ConditionsInterface;
use IfCastle\AQL\Dsl\Sql\Constant\ConstantInterface;
use IfCastle\AQL\Dsl\Sql\Constant\ConstantTupleInterface;
use IfCastle\AQL\Dsl\Sql\FunctionReference\FunctionReferenceInterface;
use IfCastle\AQL\Dsl\Sql\Helpers\JoinHelper;
use IfCastle\AQL\Dsl\Sql\Parameter\ParameterInterface;
use IfCastle\AQL\Dsl\Sql\Query\Copy;
use IfCastle\AQL\Dsl\Sql\Query\Exceptions\TransformationException;
use IfCastle\AQL\Dsl\Sql\Query\Expression\From;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Join;
use IfCastle\AQL\Dsl\Sql\Query\Expression\JoinInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Operation\LROperationInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Operation\OperationInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Subject;
use IfCastle\AQL\Dsl\Sql\Query\Expression\SubjectInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\WhereEntity;
use IfCastle\AQL\Dsl\Sql\Query\Insert;
use IfCastle\AQL\Dsl\Sql\Query\QueryInterface;
use IfCastle\AQL\Dsl\Sql\Query\UnionInterface;
use IfCastle\AQL\Dsl\Sql\Query\Select;
use IfCastle\AQL\Dsl\Sql\Query\SelectInterface;
use IfCastle\AQL\Dsl\Sql\Query\Subquery;
use IfCastle\AQL\Dsl\Sql\Query\SubqueryInterface;
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
use IfCastle\AQL\Executor\Plan\SqlQueryCommandInterface;
use IfCastle\AQL\Executor\Plan\StorageJoinCommand;
use IfCastle\AQL\Executor\Resolver\SqlRelationResolverForInsert;
use IfCastle\AQL\Executor\Resolver\SqlRelationResolverForSelect;
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
    /** Comparisons that may be true when their column is NULL, as it is for a missing related row. */
    protected const array NULL_ACCEPTING_OPERATIONS = ['IS', 'IS NOT', '<=>'];

    protected bool|null $usePreparedMode = null;

    protected int $placeholderCounter = -1;

    /**
     * The constants that rendered a placeholder into the SQL of a prepared query, by placeholder: what its
     * statement binds.
     *
     * @var array<string, ConstantInterface>
     */
    protected array $preparedConstants = [];

    /**
     *
     * @throws QueryException
     */
    #[\Override]
    public function handleQuery(BasicQueryInterface $query, NodeContextInterface $context): void
    {
        $entityName                 = $query->getMainEntityName();

        if ($entityName === '' && !self::isSelectWithoutFrom($query)) {
            throw new QueryException([
                'template'          => 'Main entity name is empty but required',
                'query'             => $query,
            ]);
        }

        foreach ([$this->scopeProcessor, $this->additionalHandler] as $handler) {
            if ($handler instanceof QueryHandlerInterface) {
                $handler->handleQuery($query, $context);
            }

            if ($query->getSubstitution() !== null) {
                return;
            }
        }

        // No entity, so no entity handler, no occurrences and no storage of its own: the default storage runs it.
        if ($entityName === '') {
            if ($query->getQueryStorage() === null) {
                $query->setQueryStorage(StorageCollectionInterface::STORAGE_MAIN);
            }

            $this->handleQueryAfter($query, $context);
            return;
        }

        $entity                     = $context->getEntity($entityName);

        if ($entity instanceof QueryHandlerInterface) {
            $entity->handleQuery($query, $context);
        }

        if ($query->getSubstitution() !== null) {
            return;
        }

        $this->defineOccurrences($query, $context, $entity);

        if ($query instanceof SubqueryInterface) {
            $this->handleSubquery($query, $context);
            return;
        }

        if ($query->getQueryStorage() === null) {
            $query->setQueryStorage($this->storageOf($entity));
        }

        $this->handleQueryAfter($query, $context);
    }

    /**
     * A SELECT without FROM is a row of expressions the server computes; it names no entity.
     */
    protected static function isSelectWithoutFrom(BasicQueryInterface $query): bool
    {
        return $query instanceof QueryInterface
            && $query->isSelect()
            && $query->getFrom() === null
            && $query->getMainEntityName() === '';
    }

    /**
     * @throws QueryException when a lock mode stands anywhere but on a top-level SELECT without UNION members
     */
    #[\Override]
    protected function normalizeQueryOrGeneratePlan(
        BasicQueryInterface        $basicQuery,
        ?ExecutionContextInterface $context = null
    ): void {
        $this->refuseLockOutsideTopLevelSelect($basicQuery);

        parent::normalizeQueryOrGeneratePlan($basicQuery, $context);
    }

    /**
     * A lock mode is meaningful on the top-level SELECT only. On a UNION member, in a subquery or in a CTE the
     * dialects disagree on its meaning (or reject it), and the dialect checks of the executors look at the top-level
     * query alone, so such a lock is refused for every dialect.
     *
     * @throws QueryException
     */
    protected function refuseLockOutsideTopLevelSelect(BasicQueryInterface $query): void
    {
        $misplaced                  = self::findLockedSelectBelow($query);

        // The lock of a union head would render before the UNION keyword, which no dialect accepts.
        if ($misplaced === null && $query instanceof SelectInterface
            && $query->getLockMode() !== null && $query->getUnion()->isNotEmpty()) {
            $misplaced              = $query;
        }

        if ($misplaced === null) {
            return;
        }

        throw new QueryException([
            'query'                 => $query,
            'clause'                => $misplaced->getLockMode()?->value,
            'template'              => 'A locking read ({clause}) is allowed only on the top-level SELECT,'
                                       . ' not on a UNION member, in a subquery or in a CTE: {query}',
        ]);
    }

    private static function findLockedSelectBelow(NodeInterface $node): ?SelectInterface
    {
        foreach ($node->getChildNodes() as $child) {
            if ($child instanceof NodeInterface === false) {
                continue;
            }

            if ($child instanceof SelectInterface && $child->getLockMode() !== null) {
                return $child;
            }

            $found                  = self::findLockedSelectBelow($child);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Outside a transaction a lock is released when the statement ends, so a locking read there protects nothing.
     * The check looks at the execution context of this very execution: a path that drops the transaction from the
     * context (a preprocessed query, a secondary command, a read routed to a replica) would turn the lock into a
     * no-op, so the query is refused instead of running unprotected.
     *
     * @throws QueryException
     */
    protected function refuseLockingReadOutsideTransaction(BasicQueryInterface $query): void
    {
        $select                     = $query->resolveNode();

        if ($select instanceof SelectInterface === false || $select->getLockMode() === null
            || $this->queryContext?->get()?->getTransaction() !== null) {
            return;
        }

        throw new QueryException([
            'query'                 => $select,
            'clause'                => $select->getLockMode()->value,
            'template'              => 'A locking read ({clause}) needs a transaction: outside one the lock is'
                                       . ' released when the statement ends; the statement is refused: {query}',
        ]);
    }

    /**
     * Registers every FROM and JOIN subject of the query as an occurrence before any column is resolved, so that
     * aliases (`FROM Work w`) and later occurrences of one entity resolve in every clause. A role join
     * (`JOIN w.publishedRevision v`) gets its entity and its relation here.
     *
     * @throws EntityDescriptorException|QueryException
     */
    protected function defineOccurrences(BasicQueryInterface $query, NodeContextInterface $context, EntityInterface $mainEntity): void
    {
        $from                       = $query instanceof QueryInterface ? $query->getFrom() : null;

        if ($from === null || $query->getResolvedAction() === QueryInterface::ACTION_INSERT) {
            return;
        }

        $context->defineOccurrence($from, $mainEntity);

        foreach ($from->getChildJoins() as $join) {
            $this->defineJoinOccurrence($join, $context);
        }
    }

    protected function defineJoinOccurrence(JoinInterface $join, NodeContextInterface $context): void
    {
        if ($join->getSubquery() !== null) {
            return;
        }

        if ($join->getRole() !== null) {
            $this->defineRoleJoin($join, $context);
        } elseif ($join->getSubject()->getSubjectName() !== '') {
            $entity                 = $context->getEntity($join->getSubject()->getSubjectName());
            $join->getSubject()->setSubjectName($context->defineOccurrence($join, $entity)->getEntityName());
        }

        foreach ($join->getChildJoins() as $child) {
            $this->defineJoinOccurrence($child, $context);
        }
    }

    /**
     * Resolves `JOIN owner.role [alias]`: the owner's relation of that name gives the joined entity and the ON
     * predicate; a later occurrence of that entity is renamed to the alias on the relation's right side.
     *
     * @throws QueryException when the owner has no such role
     */
    protected function defineRoleJoin(JoinInterface $join, NodeContextInterface $context): void
    {
        [$ownerQualifier, $role]    = $join->getRole();

        if ($join->getRelation() !== null) {
            return;
        }

        $owner                      = $context->getEntity($ownerQualifier);
        $relation                   = $owner->getRelations()[$role] ?? null;

        if ($relation === null) {
            throw new QueryException([
                'template'          => 'Entity {entity} has no role {role} (in {owner}.{role})',
                'entity'            => $owner->getEntityName(),
                'owner'             => $ownerQualifier,
                'role'              => $role,
            ]);
        }

        $occurrence                 = $context->defineOccurrence(
            $join, $context->getEntity($relation->getRightEntityName())
        );

        $join->getSubject()->setSubjectName($occurrence->getEntityName());

        // An inherited role runs through the parent entity's table: the path expands into one join per hop.
        if ($relation instanceof IndirectRelationInterface) {
            if ($occurrence->getEntityName() !== $relation->getRightEntityName()) {
                throw new QueryException([
                    'template'      => 'Role {owner}.{role} runs through other entities; a second occurrence of its target is not supported yet',
                    'owner'         => $ownerQualifier,
                    'role'          => $role,
                ]);
            }

            $this->attachToJoinIndirectRelation($relation, $join, $context);

            return;
        }

        $join->setRelation($relation->cloneWithSubjects(rightEntityName: $occurrence->getEntityName()));
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
        $entity                     = self::isSelectWithoutFrom($query)
                                    ? null : $context->getEntity($query->getMainEntityName());

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
        $this->defineJoinPredicate($join, $context, $entity);

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
        // ORDER BY and GROUP BY of a UNION name columns of its result, which no table alias may qualify.
        if (self::inUnionResultClause($column)) {
            $column->setFieldName($column->getColumnName())->setSubject('')->setSubjectAlias('');
            return;
        }

        if (self::isSelectWithoutFrom($context->getCurrentQuery())) {
            throw new QueryException([
                'template'          => 'The column {column} needs an entity, but the SELECT has no FROM: {query}',
                'column'            => $column->getColumnName(),
                'query'             => $context->getCurrentQuery(),
            ]);
        }

        $entityName                 = $column->getEntityName();

        if ($entityName !== null) {
            $entity                 = $context->getEntity($entityName);
            $this->assertNamedOccurrence($column, $entity, $context);
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
        }

        if ($this->usePreparedMode === null) {
            $this->definePreparedMode();
        }

        // A generator left by an executor that rendered the constant before would record it there, not here.
        $constant->resetResultGenerator();

        if ($this->usePreparedMode === false) {
            return;
        }

        if ($constant->getParentNode()?->getSubstitution() !== null) {
            return;
        }

        // A placeholder takes one value, so a value list stays in the statement text.
        if ($constant->isValueList() || $constant instanceof ConstantTupleInterface) {
            return;
        }

        // The placeholder is given when the constant renders, so that only what reaches the SQL is bound: a node
        // may write the value of its constant itself (LIMIT) or leave it out (the offset of a DELETE LIMIT).
        $constant->setResultGenerator($this->renderPlaceholder(...));
    }

    /**
     * Renders the constant of a prepared query as a new placeholder and records it for binding. Each occurrence has
     * a placeholder of its own, a repeated named parameter too: PDO without emulated prepares refuses a placeholder
     * that appears twice. A placeholder left from an earlier render is not reused: the names start again from the
     * first at each render.
     */
    private function renderPlaceholder(ConstantInterface $constant): string
    {
        $placeholder                = $this->generatePlaceholderName();
        $constant->asPlaceholder($placeholder);
        $this->preparedConstants[$placeholder] = $constant;

        return $placeholder;
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

        // No alias for the target of an INSERT; the SELECT of INSERT … SELECT keeps its own
        if ($context->getCurrentSqlQuery()->getResolvedAction() === QueryInterface::ACTION_INSERT) {
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

        $entityName                 = $function->getEntityName()
                                    ?? (self::isSelectWithoutFrom($context->getCurrentQuery())
                                        ? null : $context->getCurrentEntity()->getEntityName());
        $entity                     = $entityName === null ? null : $context->getEntity($entityName);

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
            // A relation marks its left columns foreign for correlated subqueries; inside a JOIN of this query
            // both sides belong to this query, including when the query itself is a subquery.
            $column
                ->setSubject($entity->getSubject())
                ->setSubjectAlias($context->defineAlias($entity, $column->isForeign() && !self::inJoinRelation($column)));
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

        // The entity is already a subject of the query (FROM, an explicit JOIN or a role join): nothing to add.
        if ($query->getFrom()->findJoin($entity->getEntityName()) !== null) {
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

    /** True for a column of the ORDER BY or GROUP BY of a UNION, outside its member queries. */
    protected static function inUnionResultClause(ColumnInterface $column): bool
    {
        for ($node = $column->getParentNode(); $node !== null; $node = $node->getParentNode()) {
            if ($node instanceof QueryInterface) {
                return false;
            }

            if ($node instanceof UnionInterface) {
                return true;
            }
        }

        return false;
    }

    protected static function inJoinRelation(ColumnInterface $column): bool
    {
        for ($node = $column->getParentNode(); $node !== null; $node = $node->getParentNode()) {
            if ($node instanceof RelationInterface) {
                return $node->getParentNode() instanceof JoinInterface;
            }

            if ($node instanceof QueryInterface) {
                return false;
            }
        }

        return false;
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
        $this->refuseSecondaryQueryUnderLock($context->getBasicQuery());

        if ($context->getCurrentSqlQuery()->isUpdate() && NodeHelper::inAssign($column)) {
            throw new QueryException([
                'template'          => 'The column {column} of {entity} cannot be used in SET of {query}: the entity is'
                                       . ' in another storage, which an UPDATE reads only to filter its own rows',
                'column'            => $column->getColumnName(),
                'entity'            => $entity->getEntityName(),
                'query'             => $context->getCurrentSqlQuery()->getAql(),
            ]);
        }

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

        if (NodeHelper::inWhere($column)) {
            $this->refuseUnmovableCondition($targetNode, $basicNode, $context);
        }

        ConsistencyHelper::checkNode($targetNode, $entity, $context);

        $basicNodeName              = $basicNode->getNodeName();
        $targetNodeCopy             = NodeHelper::copyToTransformAgain($targetNode);

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
        $this->refuseSecondaryQueryUnderLock($context->getBasicQuery());

        if ($relation instanceof DirectRelationInterface === false) {
            throw new QueryException([
                'query'             => $context->getBasicQuery(),
                'relation'          => \get_debug_type($relation),
                'template'          => 'The current Query executor can only handle relation like DirectRelationI, got {relation}',
            ]);
        }

        $fromQuery                  = $context->getCurrentSqlQuery();
        $queryAction                = $fromQuery->getResolvedAction();
        $fromCommand                = $context->getQueryCommand();

        if ($fromCommand instanceof SqlQueryCommandInterface === false) {
            throw new QueryException([
                'query'             => $fromQuery,
                'command'           => \get_debug_type($fromCommand),
                'template'          => 'The current Query executor can only process SQL queries and commands, got {command}',
            ]);
        }

        if ($this->isRestrictedBy($fromQuery, $join, $toEntity)) {
            $toCommand              = $this->newSecondaryCommand(
                ExecutionPlanInterface::RIGHT, new Select($toEntity->getEntityName())
            );

            $this->getExecutionPlan()->addCommand($toCommand);
            SqlRelationResolverForSelect::restrictByKeys($toCommand, self::relationFrom($toEntity, $relation), $fromCommand);
            $join->setSubstitution(new NodeWithCommand($toCommand));

            return;
        }

        $toQuery                    = match ($queryAction) {
            QueryInterface::ACTION_COUNT,
            QueryInterface::ACTION_SELECT  => new Select($toEntity->getEntityName()),
            QueryInterface::ACTION_INSERT,
            QueryInterface::ACTION_REPLACE => new Insert($toEntity->getEntityName()),
            QueryInterface::ACTION_COPY    => new Copy($toEntity->getEntityName()),

            default                        => throw new QueryException([
                'query'             => $fromQuery,
                'action'            => $fromQuery->getResolvedAction(),
                'template'          => 'Unknown parent query action {action} to create separate Query for Join',
            ]),
        };

        $toCommand                  = $this->newSecondaryCommand($this->relationToSide($relation), $toQuery);
        $this->getExecutionPlan()->addCommand($toCommand);

        if ($queryAction === QueryInterface::ACTION_SELECT) {
            SqlRelationResolverForSelect::resolve($fromCommand, $relation, $toCommand);
        } else {
            SqlRelationResolverForInsert::resolve($context, $toCommand, $relation, $fromCommand);
        }

        $join->setSubstitution(new NodeWithCommand($toCommand));
    }

    /**
     * A condition on an entity in another storage moves to the query of that entity, which runs first: the main query
     * keeps the rows related to the rows it reads. That is right only for a condition joined to the rest by AND and
     * false on a missing related row; anything else would change the result silently, so it is refused.
     *
     * @throws QueryException when the condition is under OR, or may be true for a missing row (IS, <=>, a function)
     */
    protected function refuseUnmovableCondition(NodeInterface $condition, NodeInterface $parent, NodeContextInterface $context): void
    {
        $operation                  = $condition instanceof LROperationInterface ? \strtoupper($condition->getOperation()) : null;
        $isMovable                  = $parent === $context->getCurrentSqlQuery()->getWhere()
                                      && $operation !== null
                                      && false === \in_array($operation, self::NULL_ACCEPTING_OPERATIONS, true);

        foreach (new \RecursiveIteratorIterator(new NodeRecursiveIterator($condition), \RecursiveIteratorIterator::SELF_FIRST) as $node) {
            $isMovable              = $isMovable && false === $node instanceof FunctionReferenceInterface;
        }

        if ($isMovable) {
            return;
        }

        throw new QueryException([
            'template'          => 'The condition {condition} on an entity in another storage must be a condition of'
                                   . ' WHERE joined by AND and false for a missing row (no IS, <=> or function): {query}',
            'condition'         => $condition->getAql(),
            'query'             => $context->getCurrentSqlQuery()->getAql(),
        ]);
    }

    /**
     * Whether the query keeps only the rows related to rows of $entity it selects: a WHERE on the entity, or any UPDATE
     * or DELETE, which change only their own entity and use another one as a filter. The entity is then queried first
     * and the query is restricted to the keys read, as a semi-join: a row of the query is kept once however many rows
     * of the entity it relates to.
     */
    protected function isRestrictedBy(QueryInterface $query, JoinInterface $join, EntityInterface $entity): bool
    {
        if ($query->isUpdate() || $query->isDelete()) {
            return true;
        }

        $where                      = $query->getWhere();

        if ($where === null) {
            return false;
        }

        return NodeHelper::findColumnByEntity($where, $entity->getEntityName()) !== null
            || NodeHelper::findColumnByEntity($where, $join->getAlias()) !== null;
    }

    /**
     * The relation seen from $entity: its left side is the entity.
     */
    protected static function relationFrom(EntityInterface $entity, DirectRelationInterface $relation): DirectRelationInterface
    {
        return $relation->getLeftEntityName() === $entity->getEntityName() ? $relation : $relation->reverseRelation();
    }

    /**
     * A command of the plan that runs $query, a query on another storage, in the transaction of the main query.
     */
    protected function newSecondaryCommand(string $stage, QueryInterface $query): StorageJoinCommand
    {
        return new StorageJoinCommand($stage, fn() => $this->executeSecondaryQuery($query), $query);
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

        $this->refuseLockingReadOutsideTransaction($query);

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
        if ($query->isOption(QueryInterface::PREPARING) && $storage instanceof SqlStatementFactoryInterface) {

            // Rendered and prepared again on every run: a value written into the text (a value list, a LIMIT) may
            // have changed since the last one, and a statement belongs to the connection of the run that prepared it.
            [$sql, $constants]      = $this->renderPrepared($query);

            if ($sql === '') {
                return new ResultNull();
            }

            $statement              = $storage->createStatement($sql, $this->queryContext);

            foreach ($constants as $constant) {
                $statement->bindParameter($constant);
            }

            $this->queryContext->get()->setSqlStatement($statement);
            $context                = $this->queryContext->get();

            $operation              = static fn() => $storage->executeStatement($statement, [], $context);

        } elseif ($statement !== null) {

            if ($storage instanceof SqlStatementFactoryInterface === false) {
                throw new QueryException([
                    'query'         => $query,
                    'template'      => 'The storage {storage} does not support the SqlStatementFactoryInterface',
                    'storage'       => $query->getQueryStorage(),
                ]);
            }

            $context                = $this->queryContext->get();
            $operation              = static fn() => $storage->executeStatement($statement, [], $context);
        } else {
            $sql                    = $this->renderSql($query);

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
        } elseif ($result instanceof InsertUpdateResultSetterInterface && $query instanceof QueryInterface && $query->isDelete()) {
            // A DELETE inserts nothing: no entity, so neither the last insert id of the connection nor values of the
            // WHERE pose as the key of a new row.
            $result->setInsertUpdateResult(new InsertUpdateResult(query: $query, affected: $result->affected()));
        }

        return $result;
    }

    /**
     * The SQL text of a transformed query in the dialect of this executor.
     * A dialect executor overrides it where the dialect writes a clause differently; the query is not changed.
     */
    protected function renderSql(BasicQueryInterface $query): string
    {
        return $query->getResultAsString();
    }

    /**
     * Renders a prepared query: its SQL and the constants its placeholders bind, by placeholder.
     *
     * @return array{string, array<string, ConstantInterface>}
     */
    private function renderPrepared(BasicQueryInterface $query): array
    {
        $this->preparedConstants    = [];
        $this->placeholderCounter   = -1;
        $sql                        = $this->renderSql($query);

        return [$sql, $this->preparedConstants];
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
     * @throws QueryException when the column names an entity that occurs under an alias or more than once
     */
    protected function assertNamedOccurrence(ColumnInterface $column, EntityInterface $entity, NodeContextInterface $context): void
    {
        $entityName                 = $column->getEntityName();

        // Relation predicates name their entities on purpose; anything else must pick an occurrence.
        if ($entityName === $entity->getEntityName() && !$context->isNamedByEntity($entityName)
            && !NodeHelper::inRelation($column)) {
            throw new QueryException([
                'template'      => 'Column {entity}.{column} is ambiguous: {entity} occurs under an alias or more than once; use the alias',
                'entity'        => $entityName,
                'column'        => $column->getColumnName(),
            ]);
        }
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
            // `JOIN` and `LEFT JOIN` keep the kind the query wrote; a comma join takes it from the relation.
            $join->setRelation($relation);

            if ($join->getJoinType() === '') {
                $join->setJoinType($this->relationToJoin($relation));
            }
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
            // The last hop of an inherited role is that role of the parent entity, not any relation to the target.
            $currentRelation        = $toEntityName === $relation->getRightEntityName()
                ? $fromEntity->getRelations()[$relation->getRelationName()] ?? $fromEntity->getRelation($toEntityName)
                : $fromEntity->getRelation($toEntityName);

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

            // The last hop joins the target, so what the query wrote on the replaced join belongs to it.
            if ($toEntityName === $relation->getRightEntityName()) {
                $this->moveWrittenParts($currentJoin, $join);
            }

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

    protected function moveWrittenParts(JoinInterface $from, JoinInterface $to): void
    {
        // A comma join has no written kind and keeps the one its relation gives.
        if ($from->getJoinType() !== '') {
            $to->setJoinType($from->getJoinType());
        }

        if ($from->getConditions() !== null) {
            $to->setConditions($from->getConditions());
        }

        $to->applyDependentJoins(...$from->getChildJoins());
    }

    /**
     * @throws EntityDescriptorException|QueryException
     */
    protected function defineJoinPredicate(JoinInterface $join, NodeContextInterface $context, EntityInterface $entity): void
    {
        if ($join->getRole() === null && $join->getConditions() !== null) {
            $this->checkAdHocJoin($join, $context, $entity);
        } elseif (false === ($join instanceof From) && $join->getRelation() === null) {
            $this->defineJoinRelation($join, $context, $entity);
        }
    }

    /**
     * `JOIN E e ON (…)` without a role is an ad hoc join: its ON is the whole predicate and no relation is looked
     * up, so θ-joins and joins of an entity to itself are written directly. Its ON is rendered into one SQL
     * statement, which needs both entities in one storage.
     *
     * @throws QueryException when the joined entity is in another storage
     */
    protected function checkAdHocJoin(JoinInterface $join, NodeContextInterface $context, EntityInterface $entity): void
    {
        $parentName                 = $join->getParentJoin()?->getSubject()->getSubjectName();

        if ($parentName !== null && $this->storageOf($context->getEntity($parentName)) !== $this->storageOf($entity)) {
            $this->throwOnAcrossStorages($join);
        }
    }

    protected function storageOf(EntityInterface $entity): string
    {
        return $entity->getStorageName() ?? StorageCollectionInterface::STORAGE_MAIN;
    }

    /**
     * @throws QueryException
     */
    protected function throwOnAcrossStorages(JoinInterface $join): never
    {
        throw new QueryException([
            'template'          => 'JOIN {subject} ON (…) cannot run across storages: '
                . 'their entities are queried separately',
            'subject'           => $join->getAlias(),
        ]);
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

        if ($join->getConditions() !== null) {
            $this->throwOnAcrossStorages($join);
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
