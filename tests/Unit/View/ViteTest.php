<?php

namespace Tests\Unit\View;

use Nitro\View\Vite;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Turning entry points into the tags that load them.
 *
 * Two modes the template must not know about: the dev server while npm run dev
 * is running, and hashed build output otherwise. Which one is a deployment
 * fact, not a design one.
 */
class ViteTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();

        $this->public = sys_get_temp_dir() . '/nitro-vite-' . bin2hex(random_bytes(4));
        mkdir($this->public . '/build/.vite', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/build/.vite/manifest.json', '/hot'] as $file) {
            if (is_file($this->public . $file)) {
                unlink($this->public . $file);
            }
        }

        @rmdir($this->public . '/build/.vite');
        @rmdir($this->public . '/build');
        @rmdir($this->public);

        parent::tearDown();
    }

    /** @param array<string, mixed> $manifest */
    private function withManifest(array $manifest): Vite
    {
        file_put_contents(
            $this->public . '/build/.vite/manifest.json',
            json_encode($manifest),
        );

        return new Vite($this->public);
    }

    private function withHotFile(string $url = 'http://localhost:5173'): Vite
    {
        file_put_contents($this->public . '/hot', $url);

        return new Vite($this->public);
    }

    // ─── Built ────────────────────────────────────────────

    public function test_a_stylesheet_becomes_a_link_to_its_hashed_file(): void
    {
        $vite = $this->withManifest([
            'resources/css/app.css' => ['file' => 'assets/app-a1b2c3.css'],
        ]);

        $this->assertSame(
            '<link rel="stylesheet" href="/build/assets/app-a1b2c3.css">',
            $vite->tags('resources/css/app.css'),
        );
    }

    public function test_a_script_becomes_a_module_tag(): void
    {
        $vite = $this->withManifest([
            'resources/js/app.js' => ['file' => 'assets/app-d4e5f6.js'],
        ]);

        $this->assertStringContainsString('type="module"', $vite->tags('resources/js/app.js'));
        $this->assertStringContainsString('/build/assets/app-d4e5f6.js', $vite->tags('resources/js/app.js'));
    }

    public function test_css_imported_from_a_script_is_linked_too(): void
    {
        $vite = $this->withManifest([
            'resources/js/app.js' => [
                'file' => 'assets/app-d4e5f6.js',
                'css' => ['assets/app-a1b2c3.css'],
            ],
        ]);

        // A stylesheet imported from JavaScript has no tag of its own. Without
        // walking the manifest the page loads its script and renders unstyled —
        // in production only, where it is hardest to see.
        $tags = $vite->tags('resources/js/app.js');

        $this->assertStringContainsString('app-a1b2c3.css', $tags);
        $this->assertStringContainsString('app-d4e5f6.js', $tags);
    }

    public function test_a_shared_stylesheet_is_linked_once(): void
    {
        $vite = $this->withManifest([
            'resources/js/app.js' => ['file' => 'assets/app.js', 'css' => ['assets/shared.css']],
            'resources/js/admin.js' => ['file' => 'assets/admin.js', 'css' => ['assets/shared.css']],
        ]);

        $tags = $vite->tags(['resources/js/app.js', 'resources/js/admin.js']);

        $this->assertSame(1, substr_count($tags, 'shared.css'));
    }

    public function test_an_entry_missing_from_the_manifest_says_so(): void
    {
        $vite = $this->withManifest(['resources/css/app.css' => ['file' => 'assets/app.css']]);

        // A page that renders with no stylesheet looks like a CSS bug and is
        // really a deploy that never ran the build.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('npm run build');

        $vite->tags('resources/js/never-built.js');
    }

    public function test_no_manifest_at_all_says_so(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no manifest');

        (new Vite($this->public))->tags('resources/css/app.css');
    }

    // ─── Development ──────────────────────────────────────

    public function test_the_hot_file_switches_to_the_dev_server(): void
    {
        $tags = $this->withHotFile()->tags('resources/css/app.css');

        $this->assertStringContainsString('http://localhost:5173/resources/css/app.css', $tags);
    }

    public function test_the_client_script_comes_first(): void
    {
        $tags = $this->withHotFile()->tags('resources/css/app.css');

        // Without it the page loads the right files once and then never
        // notices another edit.
        $this->assertStringStartsWith('<script type="module" src="http://localhost:5173/@vite/client">', $tags);
    }

    public function test_dev_mode_needs_no_manifest(): void
    {
        $vite = $this->withHotFile();

        $this->assertTrue($vite->isRunningHot());
        $this->assertStringContainsString('app.js', $vite->tags('resources/js/app.js'));
    }
}
