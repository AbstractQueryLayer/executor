<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Context;

use IfCastle\AQL\Dsl\BasicQueryInterface;
use IfCastle\AQL\Dsl\Node\NodeInterface;
use IfCastle\AQL\Dsl\Node\RecursiveIteratorByNodeIteratorInterface;
use IfCastle\AQL\Dsl\Sql\Query\Exceptions\TransformationException;
use IfCastle\AQL\Dsl\Sql\Query\Expression\JoinInterface;
use IfCastle\AQL\Dsl\Sql\Query\QueryInterface;
use IfCastle\AQL\Dsl\ValueEscaperInterface;
use IfCastle\AQL\Entity\EntityInterface;
use IfCastle\AQL\Entity\Manager\EntityStorageInterface;
use IfCastle\AQL\Executor\ColumnHandlerInterface;
use IfCastle\AQL\Executor\ParameterHandlerInterface;
use IfCastle\AQL\Executor\Plan\QueryCommandInterface;
use IfCastle\AQL\Executor\Plan\ResultComposingPlanAwareInterface;
use IfCastle\AQL\Executor\Plan\ResultProcessingPlanAwareInterface;
use IfCastle\AQL\Executor\QueryExecutorWithPlanInterface;
use IfCastle\AQL\Executor\QueryHandlerInterface;
use IfCastle\AQL\Storage\ReaderWriterInterface;
use IfCastle\AQL\Storage\SqlStatementAwareInterface;
use IfCastle\AQL\Storage\SqlStorageInterface;
use IfCastle\AQL\Storage\StorageInterface;
use IfCastle\AQL\Transaction\WithTransactionInterface;
use IfCastle\DI\ContainerMutableInterface;
use IfCastle\DI\ParentMutableInterface;

interface NodeContextInterface extends AliasResolverInterface,
    FunctionResolverInterface,
    EntityStorageInterface,
    WithTransactionInterface,
    ResultProcessingPlanAwareInterface,
    ResultComposingPlanAwareInterface,
    ReaderWriterInterface,
    DerivedEntityStorageInterface,
    ValueEscaperInterface,
    SqlStatementAwareInterface,
    ContainerMutableInterface,
    ParentMutableInterface
{
    /**
     * Registers one occurrence of an entity in the current query (a FROM or JOIN subject) and returns the entity
     * that represents it: the entity itself for its first occurrence, an AliasEntity named by the join's qualifier
     * for later ones. The join's qualifier, if any, then resolves to that entity in getEntity(). Registering the
     * same join again returns the same entity.
     *
     * @throws TransformationException when a later occurrence has no qualifier, or a qualifier is taken
     */
    public function defineOccurrence(JoinInterface $join, EntityInterface $entity): EntityInterface;

    /**
     * True when `Entity.column` names exactly one occurrence: the entity does not occur in the current query (an
     * implicit join follows), or occurs once without an alias.
     */
    public function isNamedByEntity(string $entityName): bool;

    /**
     * @var string
     */
    final public const string CONTEXT_TUPLE = 'tuple';

    /**
     * @var string
     */
    final public const string CONTEXT_JOIN = 'join';

    /**
     * @var string
     */
    final public const string CONTEXT_FILTER = 'filter';

    final public const string CONTEXT_JOIN_CONDITIONS = 'joinConditions';

    /**
     * @var string
     */
    final public const string CONTEXT_ASSIGN = 'assign';

    /**
     * @var string
     */
    final public const string CONTEXT_GROUP_BY = 'groupBy';

    /**
     * @var string
     */
    final public const string CONTEXT_ORDER_BY = 'orderBy';

    /**
     * @var string
     */
    final public const string CONTEXT_RELATIONS = 'relations';

    /**
     * @var string
     */
    final public const string CONTEXT_QUERY = 'query';

    final public const string CONTEXT_CTE = 'cte';

    public function getParentContext(): ?NodeContextInterface;

    public function getCurrentNode(): NodeInterface;

    /**
     * The method defines a factory for creating
     * a recursive iterator for normalizing a node in the specified context.
     *
     * Prototype:
     * function(NodeInterface $node, NodeContextInterface $context, NodeInterface $current = null): RecursiveIteratorByNodeIteratorInterface
     */
    public function defineTransformerIteratorFactory(callable $factory): void;

    /**
     * The method defines a factory for creating
     * a node normalizer.
     *
     * Prototype:
     * function(NodeInterface $node, NodeContextInterface $context, NodeInterface $current = null): void
     */
    public function defineTransformerFactory(callable $factory): void;

    /**
     * The method creates a recursive iterator for normalizing a node in the specified context.
     * If the context is not provided, it is extracted from the node.
     */
    public function createTransformerIterator(
        NodeInterface             $parentNode,
        NodeContextInterface|null $context = null,
        ?NodeInterface             $current = null
    ): RecursiveIteratorByNodeIteratorInterface;

    public function transform(NodeInterface $node): void;

    public function getBasicQuery(): BasicQueryInterface;

    public function getQueryExecutor(): ?QueryExecutorWithPlanInterface;

    public function getQueryHandler(): ?QueryHandlerInterface;

    public function getColumnHandler(): ?ColumnHandlerInterface;

    public function getParameterHandler(): ?ParameterHandlerInterface;

    public function getQueryCommand(): QueryCommandInterface;

    public function newNodeContext(
        NodeInterface $node,
        ?string $contextName = null,
        ?NodeContextInterface $foreignContext = null
    ): NodeContextInterface;

    public function findForeignContext(): ?NodeContextInterface;

    public function getCurrentQuery(): BasicQueryInterface;

    public function getCurrentSqlQuery(): QueryInterface;

    public function getMainQuery(): BasicQueryInterface;

    public function getMainSqlQuery(): QueryInterface;

    /**
     * Returns the current entity.
     * CurrentEntity is the main entity of the current query,
     * where CurrentQuery is the nearest Node of the QueryInterface type in the tree.
     */
    public function getCurrentEntity(): EntityInterface;

    public function getStorage(): ?StorageInterface;

    public function getSqlStorage(): ?SqlStorageInterface;

    /**
     * Returns the basic entity of the basic query.
     * The main entity is the entity that is the root of the query.
     */
    public function getBasicEntity(): EntityInterface;

    /**
     * Returns the main entity of the main query.
     * The main entity is the entity that is the root of the query.
     */
    public function getMainEntity(): EntityInterface;

    public function addToInherited(NodeContextInterface $context, bool $forRoot = true): void;

    public function findDerivedContextEntity(string $entityName): EntityInterface|null;

    /**
     * Returns nearest parent context by Node.
     */
    public function findParentContextByNode(NodeInterface $node): ?NodeContextInterface;

    /**
     * Returns current general context: CONTEXT_FILTER, CONTEXT_ASSIGN, CONTEXT_GROUP_BY, CONTEXT_ORDER_BY...
     */
    public function getContextName(): string;

    public function needDefinitionsForResult(): bool;

    /**
     * Returns TRUE if compilation mode is enabled.
     */
    public function isPreprocessing(): bool;

    public function withPreprocessing(): void;
}
