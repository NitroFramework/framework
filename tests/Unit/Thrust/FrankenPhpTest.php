<?php

namespace Tests\Unit\Thrust;

use Nitro\Thrust\Commands\ThrustCommands;
use Nitro\Thrust\FrankenPhp\BinaryFinder;
use Nitro\Thrust\FrankenPhp\ProcessInspector;
use Nitro\Thrust\FrankenPhp\ServerStateFile;
use Nitro\Thrust\Thrust;
use PHPUnit\Framework\TestCase;

/**
 * The Thrust FrankenPHP management pieces: binary discovery, server-state
 * persistence, process inspection short-circuits, and sequential task dispatch.
 */
class FrankenPhpTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'thrust-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    /** Build a path under the test's temp dir using the native separator. */
    private function path(string ...$parts): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ─── BinaryFinder ───────────────────────────────────────────────────────

    public function test_finds_binary_sitting_at_the_project_root(): void
    {
        // The finder checks both 'frankenphp' and 'frankenphp.exe', so a
        // bare-named file is found on every platform.
        $binary = $this->path('frankenphp');
        file_put_contents($binary, '');

        $this->assertSame($binary, (new BinaryFinder($this->dir))->find());
    }

    public function test_returns_null_when_no_binary_is_installed(): void
    {
        $found = (new BinaryFinder($this->dir))->find();

        if ($found !== null) {
            $this->markTestSkipped('frankenphp is on this machine\'s PATH.');
        }

        $this->assertNull($found);
    }

    // ─── ServerStateFile ────────────────────────────────────────────────────

    public function test_state_file_round_trips_state_and_pid(): void
    {
        $file = new ServerStateFile($this->path('state.json'));

        $file->writeState(['host' => '127.0.0.1', 'port' => 8080]);
        $file->writeProcessId(4242);

        $read = $file->read();
        $this->assertSame(4242, $read['masterProcessId']);
        $this->assertSame('127.0.0.1', $read['state']['host']);
        $this->assertSame(8080, $read['state']['port']);
    }

    public function test_state_file_creates_missing_directory_and_deletes(): void
    {
        $path = $this->path('nested', 'deep', 'state.json');
        $file = new ServerStateFile($path);
        $file->writeProcessId(1);

        $this->assertFileExists($path);
        $this->assertTrue($file->delete());
    }

    public function test_reading_absent_state_returns_normalised_shape(): void
    {
        $read = (new ServerStateFile($this->path('missing.json')))->read();

        $this->assertNull($read['masterProcessId']);
        $this->assertSame([], $read['state']);
    }

    // ─── ProcessInspector ───────────────────────────────────────────────────

    public function test_inspector_reports_not_running_without_a_pid(): void
    {
        // No pid recorded → serverIsRunning short-circuits false before any
        // network call, so this is deterministic offline.
        $inspector = new ProcessInspector(new ServerStateFile($this->path('state.json')));

        $this->assertFalse($inspector->serverIsRunning());
    }

    // ─── php.ini scaffolding ────────────────────────────────────────────────

    public function test_scaffolds_php_ini_for_a_bundle_without_one(): void
    {
        // A Windows-style bundle: dynamic extensions in ext/, but no php.ini.
        mkdir($this->path('ext'));
        file_put_contents($this->path('frankenphp.exe'), '');

        $ini = ThrustCommands::scaffoldPhpIni($this->dir);

        $this->assertSame($this->path('php.ini'), $ini);
        $contents = file_get_contents($ini);
        $this->assertStringContainsString('extension=pdo_mysql', $contents);
        $this->assertStringContainsString(str_replace('\\', '/', $this->path('ext')), $contents);

        // Idempotent — never clobbers an existing ini.
        $this->assertNull(ThrustCommands::scaffoldPhpIni($this->dir));
    }

    public function test_does_not_scaffold_for_a_static_binary(): void
    {
        // No ext/ dir → static build with extensions compiled in → nothing to do.
        $this->assertNull(ThrustCommands::scaffoldPhpIni($this->dir));
    }

    // ─── Thrust::concurrently ───────────────────────────────────────────────

    public function test_concurrently_runs_tasks_and_preserves_keys(): void
    {
        $results = Thrust::concurrently([
            'a' => fn () => 1 + 1,
            'b' => fn () => 'ok',
        ]);

        $this->assertSame(['a' => 2, 'b' => 'ok'], $results);
    }
}
