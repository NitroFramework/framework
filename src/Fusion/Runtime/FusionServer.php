<?php

namespace Nitro\Fusion\Runtime;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Fusion\Attributes\Server;
use ReflectionMethod;
use RuntimeException;

/**
 * The server half of the `#[Server]` boundary. When a client stub fires
 * `__fusionCall`, the runtime posts {component, method, args, state} here; this:
 *
 *   1. resolves the component through the container (DI),
 *   2. rebuilds its state from the client (public props only — Transpilable::fusionFill),
 *   3. verifies the target method is genuinely `#[Server]` and public — a client
 *      can NEVER invoke an arbitrary method, only a declared data method,
 *   4. runs it (DI + args) and returns the new public state as a patch.
 *
 * This is the only place a Fusion interaction touches DB/Auth/secrets, so all of
 * Livewire's hard-won lessons apply here (and only here).
 */
class FusionServer
{
    public function __construct(
        private ContainerInterface $container,
        private string $namespace = 'App\\Fusion\\Components\\',
    ) {
    }

    /**
     * @param array{component?: string, method?: string, args?: array, state?: array} $payload
     * @return array{state: array}
     */
    public function handle(array $payload): array
    {
        $name   = (string) ($payload['component'] ?? '');
        $method = (string) ($payload['method'] ?? '');
        $state  = (array) ($payload['state'] ?? []);
        $args   = (array) ($payload['args'] ?? []);

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException("Fusion: invalid component name.");
        }

        $class = $this->namespace . $name;
        if (! class_exists($class)) {
            throw new RuntimeException("Fusion: unknown component [{$name}].");
        }

        $component = $this->container->make($class);

        if (! method_exists($component, 'fusionFill') || ! method_exists($component, 'fusionState')) {
            throw new RuntimeException("Fusion: [{$name}] is not a client component (missing Transpilable).");
        }

        $component->fusionFill($state);

        // The method MUST be a declared #[Server] method — never anything else.
        if (! $this->isServerMethod($component, $method)) {
            throw new RuntimeException("Fusion: [{$name}::{$method}] is not a #[Server] method.");
        }

        $this->container->call([$component, $method], $args);

        return ['state' => $component->fusionState()];
    }

    private function isServerMethod(object $component, string $method): bool
    {
        if ($method === '' || ! method_exists($component, $method)) {
            return false;
        }

        $reflection = new ReflectionMethod($component, $method);

        return $reflection->isPublic()
            && ! $reflection->isStatic()
            && $reflection->getAttributes(Server::class) !== [];
    }
}
