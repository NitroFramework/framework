<?php

namespace Nitro\Fusion\Transpiler;

/**
 * The outcome of transpiling one Fusion component: the client JS class, the list
 * of `#[Server]` methods that became RPC stubs, the public props (reactive
 * state), and any client-purity violations found in Pure-UI methods.
 */
final class TranspileResult
{
    /**
     * @param string                     $js            Transpiled client JS class.
     * @param array<int, string>         $serverMethods `#[Server]` method names (now RPC stubs).
     * @param array<int, string>         $publicProps   Public property names (reactive state).
     * @param array<string, array<int,string>> $violations Pure-UI method => reasons it isn't client-pure.
     */
    public function __construct(
        public readonly string $js,
        public readonly array $serverMethods,
        public readonly array $publicProps,
        public readonly array $violations,
    ) {
    }

    /** Whether every transpiled (Pure-UI) method is client-safe. */
    public function isPure(): bool
    {
        return $this->violations === [];
    }
}
