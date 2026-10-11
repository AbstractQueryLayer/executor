<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Context;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Dsl\Node\NodeHelper;
use IfCastle\AQL\Dsl\Node\NodeInterface;
use IfCastle\AQL\Dsl\Node\RecursiveIteratorByNodeIteratorInterface;
use IfCastle\AQL\Dsl\Sql\FunctionReference\FunctionReference;
use IfCastle\AQL\Dsl\Sql\FunctionReference\FunctionReferenceInterface;
use IfCastle\AQL\Dsl\Sql\Query\Exceptions\TransformationException;
use IfCastle\AQL\Dsl\Sql\Query\Expression\JoinInterface;
use IfCastle\AQL\Dsl\Sql\Query\QueryInterface;
use IfCastle\AQL\Entity\AliasEntity;
use IfCastle\AQL\Entity\DerivedEntity\DerivedEntityInterface;
use IfCastle\AQL\Entity\EntityDescriptorInterface;
use IfCastle\AQL\Entity\EntityInterface;
use IfCastle\AQL\Entity\Functions\FunctionInterface;
use IfCastle\AQL\Entity\Manager\EntityFactoryInterface;
use IfCastle\AQL\Executor\ColumnHandlerInterface;
use IfCastle\AQL\Executor\ParameterHandlerInterface;
use IfCastle\AQL\Executor\Plan\QueryCommandInterface;
use IfCastle\AQL\Executor\Plan\ResultComposingPlanInterface;
use IfCastle\AQL\Executor\Plan\ResultProcessingPlanInterface;
use IfCastle\AQL\Executor\QueryExecutorInterface;
use IfCastle\AQL\Executor\QueryExecutorWithPlanInterface;
use IfCastle\AQL\Executor\QueryHandlerInterface;
use IfCastle\AQL\Storage\SqlStatementInterface;
use IfCastle\AQL\Storage\SqlStorageInterface;
use IfCastle\AQL\Storage\StorageCollectionInterface;
use IfCastle\AQL\Storage\StorageInterface;
use IfCastle\AQL\Transaction\TransactionAwareInterface;
use IfCastle\AQL\Transaction\TransactionInterface;
use IfCastle\DI\Container;
use IfCastle\DI\ContainerInterface;
use IfCastle\DI\ContainerMutableTrait;
use IfCastle\DI\DisposableInterface;
use IfCastle\DI\Resolver;
use IfCastle\Exceptions\LogicalException;
use IfCastle\Exceptions\UnexpectedValueType;

class NodeContext extends Container implements NodeContextInterface, DisposableInterface
{
    use ContainerMutableTrait;

    protected string $contextName   = '';

    /**
     * Key array: entity name => alias.
     * @var string[]
     */
    protected array $aliases        = [];

    /**
     * Forces the context to inherit aliases from the parent context.
     * Affects the behavior of the self::defineAlias() method.
     */
    protected bool $inheritAliases  = true;


    protected int $aliasesCount     = -1;

    /**
     * Base alias prefix that is added to all aliases.
     */
    protected string $aliasesNamespace = '';

    /**
     * Query names of subject occurrences, upper-cased first letter => the entity of the occurrence.
     * @var array<string, EntityInterface>
     */
    protected array $qualifiers     = [];

    /**
     * Entity name => number of its occurrences in this query.
     * @var array<string, int>
     */
    protected array $occurrences    = [];

    /**
     * Entity names whose first occurrence carries an alias, so the entity name no longer names it.
     * @var array<string, true>
     */
    protected array $aliasedOccurrences = [];

    /**
     * Joins already registered by defineOccurrence(), with their entities.
     * @var \WeakMap<JoinInterface, EntityInterface>|null
     */
    protected ?\WeakMap $occurrenceJoins = null;

    /**
     * List of derived entities.
     * @var EntityInterface[]
     */
    protected array $derivedEntity  = [];

    /**
     * If TRUE, the context should be considered as a derived entity Storage.
     *
     */
    protected bool $isDerivedEntityStorage  = false;

    /**
     * TRUE if storages should use only a writer.
     */
    protected bool $useOnlyWriter   = false;

    /**
     * @var \WeakReference<NodeInterface>|null
     */
    protected \WeakReference|null $currentNode = null;

    /**
     * Basic Query.
     * @var \WeakReference<BasicQueryInterface>|null
     */
    protected \WeakReference|null $basicQuery = null;

    /**
     * Current Query.
     * @var \WeakReference<QueryInterface>|null
     */
    protected \WeakReference|null $currentQuery = null;

    protected TransactionInterface|null $transaction = null;

    /**
     * External context relative to this. Used to correctly search for aliases for foreign keys.
     * @var \WeakReference<NodeContextInterface>|null
     */
    protected \WeakReference|null $foreignContext = null;

    /**
     * TRUE if definitions are required for the result.
     */
    protected bool|null $needDefinitionsForResult = null;

    /**
     * TRUE if the context is in compilation mode.
     */
    protected bool|null $isPreprocessing = null;

    protected SqlStatementInterface|null $sqlStatement = null;

    /**
     * Current Query executor
     * and query handler.
     */
    private ?QueryExecutorInterface $queryExecutor = null;

    private mixed $transformerIteratorFactory = null;

    private mixed $transformerFactory = null;

    /**
     * Inherited contexts
     * !!! A very important property for memory management !!!
     * @var NodeContextInterface[]
     */
    private array $inheritedContexts    = [];

    /**
     * SQL storage.
     * @var \WeakReference<SqlStorageInterface>|null
     */
    private \WeakReference|null $sqlStorage = null;

    public function __construct(
        ?NodeInterface              $currentNode         = null,
        ?string                     $contextName         = null,
        ?BasicQueryInterface        $query               = null,
        ?QueryExecutorInterface     $queryExecutor       = null,
        ContainerInterface|NodeInterface|null $parent        = null,
        ?NodeContextInterface        $foreignContext     = null,
    ) {
        if ($queryExecutor !== null) {
            $this->queryExecutor    = $queryExecutor;
        }

        $data                       = [];

        if ($query !== null) {
            $this->basicQuery        = \WeakReference::create($query);
        }

        if ($foreignContext !== null) {
            $this->foreignContext    = \WeakReference::create($foreignContext);
        }

        if ($currentNode instanceof QueryInterface) {
            $this->currentQuery      = \WeakReference::create($currentNode);
        }

        parent::__construct(new Resolver(), $data, $parent, true);

        $this->currentNode          = \WeakReference::create($currentNode ?? $query);

        if ($contextName !== null) {
            $this->contextName      = $contextName;
        }
    }

    #[\Override]
    public function setParentContainer(ContainerInterface $parentContainer): static
    {
        $this->redefineParentContainer($parentContainer, true);
        return $this;
    }

    #[\Override]
    public function resetParentContainer(): void
    {
        $this->redefineParentContainer(null);
    }

    #[\Override]
    public function getTransaction(): ?TransactionInterface
    {
        if ($this->transaction !== null) {
            return $this->transaction;
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof TransactionAwareInterface) {
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

        if ($this->basicQuery !== null) {
            $this->transaction       = $transaction;
            return $this;
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof NodeContextInterface) {
            return $parent->withTransaction($transaction);
        }

        throw new LogicalException('The transaction cannot be set for the context without a basic query');
    }

    #[\Override]
    public function getContextName(): string
    {
        return $this->contextName;
    }

    #[\Override]
    public function needDefinitionsForResult(): bool
    {
        if ($this->needDefinitionsForResult !== null) {
            return $this->needDefinitionsForResult;
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof NodeContextInterface) {
            return $parent->needDefinitionsForResult();
        }

        return false;
    }

    #[\Override]
    public function getBasicQuery(): BasicQueryInterface
    {
        if ($this->basicQuery !== null) {
            return $this->basicQuery->get();
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof NodeContextInterface) {
            return $parent->getBasicQuery();
        }

        throw new TransformationException([
            'template'              => 'The node {node} has no basic query. {aql}',
            'node'                  => $this->currentNode?->get(),
            'aql'                   => NodeHelper::getNearestAql($this->currentNode?->get()),
        ]);
    }

    #[\Override]
    public function getQueryExecutor(): ?QueryExecutorWithPlanInterface
    {
        if ($this->queryExecutor === null) {
            $parent                 = $this->getParentContainer();

            if ($parent instanceof NodeContextInterface) {
                return $parent->getQueryExecutor();
            }
        }

        return $this->queryExecutor instanceof QueryExecutorWithPlanInterface ? $this->queryExecutor : null;
    }

    #[\Override]
    public function getQueryHandler(): ?QueryHandlerInterface
    {
        $handler                    = $this->getQueryExecutor();
        return $handler instanceof QueryHandlerInterface ? $handler : null;
    }

    #[\Override]
    public function getColumnHandler(): ?ColumnHandlerInterface
    {
        $handler                    = $this->getQueryExecutor();
        return $handler instanceof ColumnHandlerInterface ? $handler : null;
    }

    #[\Override]
    public function getParameterHandler(): ?ParameterHandlerInterface
    {
        $handler                    = $this->getQueryExecutor();
        return $handler instanceof ParameterHandlerInterface ? $handler : null;
    }

    #[\Override]
    public function getQueryCommand(): QueryCommandInterface
    {
        return $this->getQueryExecutor()->resolveQueryCommand(
            $this->getCurrentQuery(), $this->getBasicQuery()
        );
    }

    #[\Override]
    public function newNodeContext(
        NodeInterface $node,
        ?string $contextName = null,
        ?NodeContextInterface $foreignContext = null
    ): NodeContextInterface {
        return new self(currentNode: $node, contextName: $contextName, parent: $this, foreignContext: $foreignContext);
    }

    #[\Override]
    public function getParentContext(): ?NodeContextInterface
    {
        $parent                     = $this->getParentContainer();

        if ($parent instanceof NodeContextInterface) {
            return $parent;
        }

        return null;
    }

    #[\Override]
    public function getCurrentNode(): NodeInterface
    {
        return $this->currentNode?->get() ?? $this->getBasicQuery();
    }

    #[\Override]
    public function defineTransformerIteratorFactory(callable $factory): void
    {
        $this->transformerIteratorFactory = $factory;
    }

    #[\Override]
    public function defineTransformerFactory(callable $factory): void
    {
        $this->transformerFactory   = $factory;
    }

    /**
     * @throws TransformationException
     */
    #[\Override]
    public function createTransformerIterator(
        NodeInterface             $parentNode,
        NodeContextInterface|null $context = null,
        ?NodeInterface             $current = null
    ): RecursiveIteratorByNodeIteratorInterface {
        $factory                    = $this->transformerIteratorFactory;

        if ($factory !== null) {
            $context                ??= $parentNode->getNodeContext();
            return $factory($parentNode, $context, $current);
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof NodeContextInterface) {
            return $parent->createTransformerIterator($parentNode, $context, $current);
        }

        throw new TransformationException([
            'template'              => 'The node {node} has no transformation iterator factory. {aql}',
            'node'                  => $parentNode::class,
            'aql'                   => $parentNode->getAql(),
        ]);
    }

    #[\Override]
    public function transform(NodeInterface $node): void
    {
        $factory                    = $this->transformerFactory;

        if ($factory !== null) {
            $factory($node, $this);
            return;
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof NodeContextInterface) {
            $parent->transform($node);
            return;
        }

        throw new TransformationException([
            'template'              => 'The node {node} has no transformer factory. {aql}',
            'node'                  => $node::class,
            'aql'                   => $node->getAql(),
        ]);
    }

    #[\Override]
    public function findForeignContext(): ?NodeContextInterface
    {
        if ($this->foreignContext !== null) {
            return $this->foreignContext->get();
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof NodeContextInterface) {
            return $parent->findForeignContext();
        }

        return null;
    }

    #[\Override]
    public function getCurrentQuery(): BasicQueryInterface
    {
        if ($this->currentQuery !== null) {
            return $this->currentQuery->get();
        }

        $parent                     = $this->getParentContext();

        if ($parent instanceof NodeContextInterface) {
            return $parent->getCurrentQuery();
        }

        return $this->getBasicQuery();
    }

    #[\Override]
    public function getCurrentSqlQuery(): QueryInterface
    {
        $query                      = $this->getCurrentQuery();

        if ($query instanceof QueryInterface) {
            return $query;
        }

        throw new TransformationException([
            'template'              => 'The node {node} has no SQL query. {aql}',
            'node'                  => $this->currentNode?->get(),
            'aql'                   => NodeHelper::getNearestAql($this->currentNode?->get()),
        ]);
    }

    #[\Override]
    public function getMainQuery(): BasicQueryInterface
    {
        if ($this->currentQuery?->get() !== null && $this->currentQuery->get()->getParentNode() === null) {
            return $this->currentQuery->get();
        }

        $parent                     = $this->getParentContext();

        if ($parent instanceof NodeContextInterface) {
            return $parent->getMainQuery();
        }

        return $this->getBasicQuery();
    }

    #[\Override]
    public function getMainSqlQuery(): QueryInterface
    {
        $query                      = $this->getMainQuery();

        if ($query instanceof QueryInterface) {
            return $query;
        }

        throw new TransformationException([
            'template'              => 'The node {node} has no main SQL query. {aql}',
            'node'                  => $this->currentNode?->get(),
            'aql'                   => NodeHelper::getNearestAql($this->currentNode?->get()),
        ]);
    }

    #[\Override]
    public function getCurrentEntity(): EntityInterface
    {
        return $this->getEntity($this->getCurrentQuery()->getMainEntityName());
    }

    #[\Override]
    public function getStorage(): ?StorageInterface
    {
        $query                      = $this->getCurrentQuery();

        // A query without an entity (SELECT without FROM) names its storage itself; null stands for the default one.
        $storageName                = $query->getMainEntityName() === ''
                                    ? $query->getQueryStorage()
                                    : $this->getCurrentEntity()->getStorageName();
        $storageCollection          = $this->resolveDependency(StorageCollectionInterface::class);

        if ($storageCollection instanceof StorageCollectionInterface) {
            return $storageCollection->findStorage($storageName);
        }

        return null;
    }

    /**
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function getSqlStorage(): ?SqlStorageInterface
    {
        $storage                    = $this->sqlStorage?->get();

        if ($storage !== null) {
            return $storage;
        }

        $storage                    = $this->getStorage();

        if ($storage === null || $storage instanceof SqlStorageInterface) {
            $this->sqlStorage       = \WeakReference::create($storage);
            return $storage;
        }

        throw new UnexpectedValueType('storage', $storage, SqlStorageInterface::class);
    }

    #[\Override]
    public function getBasicEntity(): EntityInterface
    {
        return $this->getEntity($this->getBasicQuery()->getMainEntityName());
    }

    #[\Override]
    public function getMainEntity(): EntityInterface
    {
        return $this->getEntity($this->getMainQuery()->getMainEntityName());
    }

    #[\Override]
    public function addToInherited(NodeContextInterface $context, bool $forRoot = true): void
    {
        if ($forRoot) {
            $parent                 = $this->getParentContainer();

            if ($parent instanceof NodeContextInterface) {
                $parent->addToInherited($context, true);
            }
        }

        $this->inheritedContexts[]  = $context;
    }

    #[\Override]
    public function defineOccurrence(JoinInterface $join, EntityInterface $entity): EntityInterface
    {
        $this->occurrenceJoins      ??= new \WeakMap();

        if (isset($this->occurrenceJoins[$join])) {
            return $this->occurrenceJoins[$join];
        }

        $name                       = $entity->getEntityName();
        $qualifier                  = $join->getQualifier();
        $occurrence                 = $entity;

        if (($this->occurrences[$name] ?? 0) > 0) {
            if ($qualifier === '') {
                throw new TransformationException([
                    'template'      => 'Entity {entity} occurs more than once in the query; name the occurrence with an alias',
                    'entity'        => $name,
                ]);
            }

            $occurrence             = new AliasEntity(\ucfirst($qualifier), $entity);
        }

        if ($qualifier !== '') {
            $this->defineQualifier($qualifier, $occurrence);
        }

        if ($occurrence === $entity && $qualifier !== '' && \ucfirst($qualifier) !== $name) {
            $this->aliasedOccurrences[$name] = true;
        }

        $this->occurrences[$name]   = ($this->occurrences[$name] ?? 0) + 1;
        $this->occurrenceJoins[$join] = $occurrence;

        return $occurrence;
    }

    #[\Override]
    public function isNamedByEntity(string $entityName): bool
    {
        $count                      = $this->occurrences[$entityName] ?? 0;

        return $count === 0 || ($count === 1 && !isset($this->aliasedOccurrences[$entityName]));
    }

    /**
     * @throws TransformationException when the qualifier is taken in this query or names a different entity
     */
    protected function defineQualifier(string $qualifier, EntityInterface $entity): void
    {
        $key                        = \ucfirst($qualifier);

        if (\array_key_exists($key, $this->qualifiers)) {
            throw new TransformationException([
                'template'          => 'Alias {alias} is used twice in the query',
                'alias'             => $qualifier,
            ]);
        }

        // An alias spelled as another entity of the query would make `Alias.column` mean two things.
        if ($key !== $entity->getEntityName() && \array_key_exists($key, $this->occurrences)) {
            throw new TransformationException([
                'template'          => 'Alias {alias} is the name of entity {entity} in the same query',
                'alias'             => $qualifier,
                'entity'            => $key,
            ]);
        }

        $this->qualifiers[$key]     = $entity;
    }

    /**
     * @param string $key a qualifier with its first letter upper-cased
     */
    protected function findQualifiedEntity(string $key): ?EntityInterface
    {
        $entity                     = $this->qualifiers[$key] ?? null;

        if ($entity !== null) {
            return $entity;
        }

        $parent                     = $this->getParentContainer();

        return $parent instanceof self ? $parent->findQualifiedEntity($key) : null;
    }

    #[\Override]
    public function getEntity(string $entityName, bool $isRaw = false): EntityInterface
    {
        return $this->findContextEntity($entityName) ?? $this->getEntityFactory()->getEntity($entityName, $isRaw);
    }

    #[\Override]
    public function findEntity(string $entityName, bool $isRaw = false): ?EntityInterface
    {
        return $this->findContextEntity($entityName) ?? $this->getEntityFactory()->findEntity($entityName, $isRaw);
    }

    /**
     * An occurrence or alias of this query or an outer one, else a derived entity of the context.
     */
    private function findContextEntity(string $entityName): ?EntityInterface
    {
        return $this->findQualifiedEntity(\ucfirst($entityName)) ?? $this->findDerivedContextEntity($entityName);
    }

    #[\Override]
    public function quote(mixed $value): string
    {
        return $this->getSqlStorage()->quote($value);
    }

    #[\Override]
    public function getSqlStatement(): SqlStatementInterface|null
    {
        return $this->sqlStatement;
    }

    #[\Override]
    public function setSqlStatement(SqlStatementInterface $sqlStatement): void
    {
        $this->sqlStatement         = $sqlStatement;
    }

    #[\Override]
    public function escape(string $value): string
    {
        return $this->getSqlStorage()->escape($value);
    }

    protected function getEntityFactory(): EntityFactoryInterface
    {
        $entityFactory              = $this->resolveDependency(EntityFactoryInterface::class);

        if ($entityFactory instanceof EntityFactoryInterface === false) {
            throw new UnexpectedValueType('env.' . EntityFactoryInterface::class, $entityFactory, EntityFactoryInterface::class);
        }

        return $entityFactory;
    }

    #[\Override]
    public function setEntity(EntityInterface $entity): static
    {
        $this->derivedEntity[$entity->getEntityName()] = $entity;

        if (\array_key_exists($entity->getEntityName(), $this->aliases)) {
            throw new TransformationException([
                'template'          => 'The alias {alias} for entity {entity} already defined in the aql {aql}',
                'alias'             => $entity->getEntityName(),
                'entity'            => $entity->getEntityName(),
                'aql'               => NodeHelper::getNearestAql($this->currentNode?->get()),
            ]);
        }

        $this->aliases[$entity->getEntityName()]       = \lcfirst($entity->getEntityName());

        return $this;
    }

    #[\Override]
    public function findDerivedContextEntity(string $entityName): ?EntityInterface
    {
        $entityName                 = \ucfirst($entityName);

        if (\array_key_exists($entityName, $this->derivedEntity)) {
            return $this->derivedEntity[$entityName];
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof NodeContextInterface) {
            return $parent->findDerivedContextEntity($entityName);
        }

        return null;
    }

    /**
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function findTypicalEntity(string $entityName, bool $isRaw = false): ?EntityInterface
    {
        return $this->getEntityFactory()->findTypicalEntity($entityName, $isRaw);
    }

    /**
     * @throws UnexpectedValueType
     */
    #[\Override]
    public function newEntity(string $entityName): EntityInterface & EntityDescriptorInterface
    {
        return $this->getEntityFactory()->newEntity($entityName);
    }

    #[\Override]
    public function findParentContextByNode(NodeInterface $node): ?NodeContextInterface
    {
        if ($this->currentNode?->get() === $node) {
            return $this;
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof NodeContextInterface) {
            return $parent->findParentContextByNode($node);
        }

        return null;
    }

    #[\Override]
    public function findEntityClass(string $entityName): ?string
    {
        $entityFactory              = $this->resolveDependency(EntityFactoryInterface::class);

        if ($entityFactory instanceof EntityFactoryInterface) {
            return $entityFactory->findEntityClass($entityName);
        }

        throw new UnexpectedValueType('env.' . EntityFactoryInterface::class, $entityFactory, EntityFactoryInterface::class);
    }

    #[\Override]
    public function resolveFunction(FunctionReferenceInterface|string $functionReference, ?NodeContextInterface $context = null): ?FunctionInterface
    {
        $context                    ??= $this;

        if (\is_string($functionReference)) {
            $functionReference      = (new FunctionReference($functionReference))->asGlobal();
        }

        $entity                     = $functionReference->getEntityName() === null || $functionReference->getEntityName() === '' ?
                                    $context->getCurrentEntity()
                                    : $context->getEntity($functionReference->getEntityName());

        // Try to find function in entity
        $function                   = $entity->findFunction($functionReference);

        if ($function !== null) {
            return $function;
        }

        // Try to find function in Storage
        $storage                    = $context->getStorage();

        if ($storage instanceof FunctionResolverInterface) {
            $function               = $storage->resolveFunction($functionReference, $context);

            if ($function !== null) {
                return $function;
            }
        }

        // Try to find function in QueryExecutor
        $queryExecutor              = $context->getQueryExecutor();

        if ($queryExecutor instanceof FunctionResolverInterface) {
            $function               = $queryExecutor->resolveFunction($functionReference, $context);

            if ($function !== null) {
                return $function;
            }
        }

        return null;
    }

    #[\Override]
    public function isPreprocessing(): bool
    {
        if ($this->isPreprocessing !== null) {
            return $this->isPreprocessing;
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof NodeContextInterface) {
            $this->isPreprocessing  = $parent->isPreprocessing();
        }

        return false;
    }

    #[\Override]
    public function withPreprocessing(): void
    {
        $this->isPreprocessing      = true;
    }

    #[\Override]
    public function defineAlias(string|EntityInterface $entity, bool $isForeign = false): string
    {
        // Return foreign alias
        if ($isForeign && ($foreignContext = $this->findForeignContext()) !== null) {
            return $foreignContext->defineAlias($entity);
        }

        if ($this->inheritAliases && ($parent = $this->getParentContainer()) instanceof NodeContextInterface) {
            return $parent->defineAlias($entity);
        }

        $subject                    = $entity instanceof EntityInterface ? $entity->getEntityName() : $entity;

        if (\array_key_exists($subject, $this->aliases)) {
            return $this->aliases[$subject];
        }

        $this->aliases[$subject]    = $this->generateAlias();

        return $this->aliases[$subject];
    }

    #[\Override]
    public function findAlias(string $subject, bool $isForeign = false): ?string
    {
        // Return foreign alias
        if ($isForeign && ($foreignContext = $this->findForeignContext()) !== null) {
            return $foreignContext->findAlias($subject);
        }

        if ($this->inheritAliases && ($parent = $this->getParentContainer()) instanceof AliasResolverInterface) {
            return $parent->findAlias($subject);
        }

        return $this->aliases[$subject] ?? null;
    }

    #[\Override]
    public function isAliasExists(string $subject): bool
    {
        return \array_key_exists($subject, $this->aliases);
    }

    #[\Override]
    public function generateAlias($prefix = 't'): string
    {
        ++$this->aliasesCount;
        return $this->aliasesNamespace . $prefix . $this->aliasesCount;
    }

    #[\Override]
    public function setAliasesNamespace(string $aliasesNamespace): static
    {
        $this->inheritAliases       = false;
        $this->aliasesNamespace     = $aliasesNamespace;
        return $this;
    }

    #[\Override]
    public function notInheritAliases(): static
    {
        $this->inheritAliases       = false;

        return $this;
    }

    #[\Override]
    public function useOnlyWriter(): bool
    {
        return $this->useOnlyWriter;
    }

    #[\Override]
    public function setUseOnlyWriter(): static
    {
        $this->useOnlyWriter        = true;

        return $this;
    }

    #[\Override]
    public function getResultProcessingPlan(): ResultProcessingPlanInterface
    {
        return $this->getQueryExecutor()->getExecutionPlan();
    }

    #[\Override]
    public function getResultComposingPlan(): ResultComposingPlanInterface
    {
        return $this->getQueryExecutor()->getExecutionPlan();
    }

    /**
     * @throws TransformationException
     */
    #[\Override]
    public function addDerivedEntity(DerivedEntityInterface $entity): static
    {
        $storage                    = $this->findDerivedEntityStorage();

        if ($storage === $this) {
            $this->derivedEntity[\ucfirst($entity->getEntityName())] = $entity;
            return $this;
        }

        if ($storage === null) {
            throw new TransformationException([
                'template'              => 'The derived storage can\'t be defined in context {context}. {aql}',
                'context'               => $this::class,
                'node'                  => $this->currentNode?->get(),
                'aql'                   => NodeHelper::getNearestAql($this->currentNode?->get()),
            ]);
        }

        $storage->addDerivedEntity($entity);

        return $this;
    }

    #[\Override]
    public function findDerivedEntityStorage(): DerivedEntityStorageInterface|null
    {
        if ($this->isDerivedEntityStorage) {
            return $this;
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof DerivedEntityStorageInterface) {
            return $parent->findDerivedEntityStorage();
        }

        return null;
    }

    #[\Override]
    public function asDerivedEntityStorage(): static
    {
        $this->isDerivedEntityStorage = true;

        return $this;
    }

    #[\Override]
    public function findDerivedEntity(
        string $entityName,
        bool   $partialDefinition = false
    ): DerivedEntityInterface|null {
        if ($this->isDerivedEntityStorage) {
            return $this->derivedEntity[\ucfirst($entityName)] ?? null;
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof DerivedEntityStorageInterface) {
            return $parent->findDerivedEntity($entityName, $partialDefinition);
        }

        return null;
    }

    #[\Override]
    public function getListOfDerivedEntities(): array
    {
        if ($this->isDerivedEntityStorage) {
            return $this->derivedEntity;
        }

        $parent                     = $this->getParentContainer();

        if ($parent instanceof DerivedEntityStorageInterface) {
            return $parent->getListOfDerivedEntities();
        }

        return [];
    }

    #[\Override]
    public function dispose(): void
    {
        parent::dispose();

        //  Free inherited contexts
        $inherited                  = $this->inheritedContexts;
        $this->inheritedContexts    = [];

        foreach ($inherited as $item) {
            $item->dispose();
        }

        $derivedEntity              = $this->derivedEntity;
        $this->derivedEntity        = [];

        foreach ($derivedEntity as $entity) {
            if ($entity instanceof DisposableInterface) {
                $entity->dispose();
            }
        }

        $queryExecutor              = $this->queryExecutor;
        $this->queryExecutor        = null;

        $this->transformerIteratorFactory = null;
        $this->transformerFactory   = null;
        $this->foreignContext       = null;
        $this->currentNode          = null;
        $this->basicQuery           = null;
        $this->currentQuery         = null;
        $this->transaction          = null;

        if ($queryExecutor instanceof DisposableInterface) {
            $queryExecutor->dispose();
        }
    }
}
