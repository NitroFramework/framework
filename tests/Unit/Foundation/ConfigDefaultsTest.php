<?php

namespace Tests\Unit\Foundation;

use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Config merges framework defaults (config-defaults.php) as the base layer, with
 * the app's config/*.php recursively overlaid on top. This guarantees every key
 * a framework internal reads resolves, so internals need no inline fallbacks.
 */
class ConfigDefaultsTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/nitro-config-' . bin2hex(random_bytes(4));
        mkdir($this->configDir, 0775, true);

        // App overrides only a couple of keys; everything else must fall back to
        // the framework defaults. auth.php overrides one nested redirect only.
        file_put_contents($this->configDir . '/app.php', "<?php\nreturn ['debug' => true];\n");
        file_put_contents(
            $this->configDir . '/auth.php',
            "<?php\nreturn ['redirects' => ['login' => '/signin']];\n"
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->configDir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->configDir);
    }

    public function test_defaults_fill_gaps_and_app_overrides_win_with_recursive_merge(): void
    {
        $config = new Config($this->stubPaths($this->configDir), true);

        // A key with no app config file at all still resolves from defaults.
        $this->assertSame('log', $config->get('mail.driver'));
        $this->assertSame(10800, $config->get('auth.password_timeout'));

        // App override wins over the framework default.
        $this->assertTrue($config->get('app.debug'));
        $this->assertSame('/signin', $config->get('auth.redirects.login'));

        // Recursive merge: a sibling the app didn't override survives from defaults.
        $this->assertSame('/dashboard', $config->get('auth.redirects.dashboard'));

        // A key the app file omits entirely still comes from the default layer.
        $this->assertSame('production', $config->get('app.env'));
    }

    /**
     * Not everything under config/ is configuration.
     *
     * routes.php registers routes and directives.php returns a callback a
     * provider invokes at boot; both are required by the layer that owns them.
     * Merging them in published a key nothing reads, and since a callback
     * cannot be var_export'd, `optimize` then dropped it and warned about a
     * divergence that did not exist — the directives were registered either
     * way.
     */
    public function test_files_that_are_not_configuration_are_not_merged(): void
    {
        file_put_contents(
            $this->configDir . '/directives.php',
            "<?php\nreturn function (\$app) { return 'registered'; };\n"
        );
        file_put_contents($this->configDir . '/routes.php', "<?php\nreturn ['never' => 'merged'];\n");

        $config = new Config($this->stubPaths($this->configDir), true);

        $this->assertNull($config->get('directives'), 'a boot callback is not a config value');
        $this->assertNull($config->get('routes'), 'the routes file is not a config value');

        // And an ordinary config file beside them is still merged.
        $this->assertTrue($config->get('app.debug'));
    }

    /** A PathRegistry whose config()/cache() point at the temp dir. */
    private function stubPaths(string $dir): PathRegistry
    {
        return new class($dir) extends PathRegistry {
            public function __construct(private string $dir) {}
            public function config(string $path = ''): string { return $this->dir; }
            public function cache(string $path = ''): string { return $this->dir . '/does-not-exist'; }
        };
    }
}
