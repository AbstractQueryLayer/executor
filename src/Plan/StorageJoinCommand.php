<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Plan;

/**
 * The query of a plan that reads an entity kept in another storage than the main query's. The plan binds its keys
 * anew on each run, so a plan made only of such commands may run again.
 */
class StorageJoinCommand extends SqlQueryCommand {}
