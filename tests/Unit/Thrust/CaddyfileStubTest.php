<?php

namespace Tests\Unit\Thrust;

use PHPUnit\Framework\TestCase;

/**
 * The Caddyfile stub scaffolded by `thrust:install` must not let its static
 * *.js file_server rule shadow the framework's dynamic runtime routes. Those
 * routes (/nitro/hx-component.js, /livewire/livewire.js) end in .js but are
 * served by PHP; if file_server catches them first it 404s and htmx/livewire
 * never load — every nav link silently falls back to a full page load.
 */
class CaddyfileStubTest extends TestCase
{
    private function stub(): string
    {
        $path = dirname(__DIR__, 3) . '/src/Thrust/stubs/Caddyfile';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_static_matcher_excludes_framework_runtime_routes(): void
    {
        $stub = $this->stub();

        // The static-asset matcher globs *.js; it must also exclude the dynamic
        // framework route prefixes so they fall through to php_server.
        $this->assertMatchesRegularExpression(
            '/not\s+path\s+[^\n]*\/nitro\/\*/',
            $stub,
            'Caddyfile stub must exclude /nitro/* from the static file_server matcher'
        );
        $this->assertMatchesRegularExpression(
            '/not\s+path\s+[^\n]*\/livewire\/\*/',
            $stub,
            'Caddyfile stub must exclude /livewire/* from the static file_server matcher'
        );
    }
}
