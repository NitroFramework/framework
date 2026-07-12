<?php

namespace Nitro\Fusion\Build;

use RuntimeException;

/**
 * Thrown when a `#[Client]` component fails the client-purity check — a Pure-UI
 * method reaches server-only surface (a model, a static call, `new SomeClass`).
 * The build fails loudly rather than shipping server logic to the browser.
 */
class FusionBuildException extends RuntimeException
{
    /** @param array<string, array<int, string>> $violations method => reasons */
    public function __construct(
        public readonly string $component,
        public readonly array $violations,
    ) {
        $lines = [];
        foreach ($violations as $method => $reasons) {
            foreach ($reasons as $reason) {
                $lines[] = "  {$component}::{$method}() — {$reason}";
            }
        }
        parent::__construct(
            "Fusion: component [{$component}] is not client-pure:\n" . implode("\n", $lines)
        );
    }
}
