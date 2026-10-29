<?php

declare(strict_types=1);

namespace IfCastle\AQL\Executor\Context;

/**
 * The execution context can be the context of the current coroutine.
 * It provides an abstraction over variables stored in the context.
 */
interface ContextVariablesInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    /**
     * Runs the callback once the current context ends, such as when its coroutine completes. The main coroutine of a
     * CLI process ends only at shutdown, so its callbacks run there.
     *
     * @param callable(): void $callback must not throw: the end of a context has no caller to report to
     */
    public function defer(callable $callback): void;
}
