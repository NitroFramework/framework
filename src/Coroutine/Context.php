<?php

namespace Nitro\Coroutine;

/**
 * Per-coroutine state — the coroutine equivalent of a request-scoped store.
 *
 * Each coroutine owns an ArrayObject that is discarded when it ends, so values set
 * here are automatically isolated to the running coroutine and cleaned up with it.
 * Outside any coroutine, values fall back to a process-global store.
 *
 * Use it for "current" things you'd otherwise thread everywhere — a request id, a
 * trace span, the acting user — when fanning work across coroutines.
 */
class Context
{
    /** @var array<string, mixed> */
    private static array $global = [];

    public static function set(string $key, mixed $value): mixed
    {
        $co = self::coroutine();
        if ($co !== null) {
            $co->context[$key] = $value;
        } else {
            self::$global[$key] = $value;
        }

        return $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $co = self::coroutine();
        if ($co !== null) {
            return $co->context[$key] ?? $default;
        }

        return self::$global[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        $co = self::coroutine();

        return $co !== null ? isset($co->context[$key]) : isset(self::$global[$key]);
    }

    public static function forget(string $key): void
    {
        $co = self::coroutine();
        if ($co !== null) {
            unset($co->context[$key]);
        } else {
            unset(self::$global[$key]);
        }
    }

    /** A snapshot of the running coroutine's context (for handing to a child). @return array<string, mixed> */
    public static function all(): array
    {
        $co = self::coroutine();

        return $co !== null ? $co->context->getArrayCopy() : self::$global;
    }

    /**
     * Seed the running coroutine's context from a parent snapshot — call at the top
     * of a Co::go() body when the child needs to inherit the parent's scope.
     *
     * @param array<string, mixed> $parent  Usually Context::all() captured before spawn.
     * @param string[]             $keys    Limit to these keys, or all if empty.
     */
    public static function inherit(array $parent, array $keys = []): void
    {
        foreach ($parent as $key => $value) {
            if ($keys === [] || in_array($key, $keys, true)) {
                self::set($key, $value);
            }
        }
    }

    private static function coroutine(): ?Coroutine
    {
        return Scheduler::current()?->currentCoroutine();
    }
}
