<?php

namespace Tests\Unit\Console;

use Nitro\Console\Commands\StorageLinkCommand;
use Nitro\Console\ExitCode;
use Nitro\Console\OutputFormatter;
use Nitro\Filesystem\Filesystem;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\PathRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Connecting the public disk to somewhere the web server serves.
 *
 * A 'public' disk is rooted at storage/app/public and carries a url of
 * APP_URL/storage. Nothing serves that path until public/storage points at the
 * disk's root, so without this the configuration describes a URL the
 * application cannot honour and every upload comes back 404.
 */
class StorageLinkCommandTest extends TestCase
{
    private string $root;

    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/nitro-link-cmd-' . getmypid() . '-' . uniqid();
        $this->files = new Filesystem();

        $this->files->ensureDirectoryExists($this->root . '/public');
        $this->files->ensureDirectoryExists($this->root . '/storage/app/public');
    }

    protected function tearDown(): void
    {
        // The link goes before the tree, so removing the tree cannot reach
        // through it — the command's own subject matter.
        $link = $this->root . '/public/storage';

        if ($this->files->isLink($link)) {
            @rmdir($link) || @unlink($link);
        }

        $this->files->deleteDirectory($this->root);

        parent::tearDown();
    }

    private function command(array $config = []): StorageLinkCommand
    {
        $repository = new class (['filesystems' => $config]) implements ConfigRepository {
            public function __construct(private array $items) {}

            public function has(string $key): bool
            {
                return $this->get($key) !== null;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                $value = $this->items;

                foreach (explode('.', $key) as $segment) {
                    if (! is_array($value) || ! array_key_exists($segment, $value)) {
                        return $default;
                    }

                    $value = $value[$segment];
                }

                return $value;
            }

            public function all(): array
            {
                return $this->items;
            }

            public function set(string $key, mixed $value): void {}
        };

        return new StorageLinkCommand(
            new PathRegistry($this->root),
            $repository,
            $this->files,
            new OutputFormatter(decorated: false),
        );
    }

    /**
     * Run a command, keeping what it prints out of the suite's own output.
     *
     * @param array<int, string> $arguments
     */
    private function execute(StorageLinkCommand $command, string $signature, array $arguments = []): int
    {
        ob_start();

        try {
            return $command->handle($signature, $arguments);
        } finally {
            $this->printed = (string) ob_get_clean();
        }
    }

    /** What the last run printed, for a test that cares. */
    private string $printed = '';

    private function link(): string
    {
        return $this->root . '/public/storage';
    }

    public function test_it_connects_public_storage_to_the_disk_root(): void
    {
        $this->files->put($this->root . '/storage/app/public/avatar.png', 'pretend png');

        $this->assertFalse($this->files->exists($this->link()), 'nothing serves it beforehand');

        $this->assertSame(ExitCode::SUCCESS, $this->execute($this->command(),'storage:link'));

        $this->assertTrue($this->files->exists($this->link()));
        $this->assertSame('pretend png', $this->files->get($this->link() . '/avatar.png'));
    }

    /** Running it twice must not be destructive. */
    public function test_an_existing_link_is_left_alone_without_force(): void
    {
        $command = $this->command();

        $this->execute($command,'storage:link');

        $this->assertSame(ExitCode::SUCCESS, $this->execute($command,'storage:link'));
        $this->assertStringContainsString('already exists', $this->printed);
        $this->assertTrue($this->files->exists($this->link()));
    }

    public function test_force_recreates_the_link(): void
    {
        $command = $this->command();

        $this->execute($command,'storage:link');
        $this->files->put($this->root . '/storage/app/public/after.txt', 'still here');

        $this->assertSame(ExitCode::SUCCESS, $this->execute($command,'storage:link', ['--force']));
        $this->assertSame('still here', $this->files->get($this->link() . '/after.txt'));
    }

    /**
     * A real directory at that path holds someone's files. Removing it to make
     * a link would be the command deleting data it was never asked to touch.
     */
    public function test_a_real_directory_in_the_way_is_refused_not_removed(): void
    {
        $this->files->ensureDirectoryExists($this->link());
        $this->files->put($this->link() . '/someones-file.txt', 'do not delete me');

        $this->assertSame(ExitCode::FAILURE, $this->execute($this->command(),'storage:link', ['--force']));
        $this->assertStringContainsString('is not a link', $this->printed);
        $this->assertSame('do not delete me', $this->files->get($this->link() . '/someones-file.txt'));
    }

    public function test_the_links_can_be_configured(): void
    {
        $this->files->ensureDirectoryExists($this->root . '/elsewhere');
        $this->files->put($this->root . '/elsewhere/marker.txt', 'found');

        $command = $this->command([
            'links' => [$this->root . '/public/assets' => $this->root . '/elsewhere'],
        ]);

        $this->assertSame(ExitCode::SUCCESS, $this->execute($command,'storage:link'));
        $this->assertSame('found', $this->files->get($this->root . '/public/assets/marker.txt'));

        @rmdir($this->root . '/public/assets') || @unlink($this->root . '/public/assets');
    }

    public function test_unlink_removes_the_link_and_keeps_the_target(): void
    {
        $this->files->put($this->root . '/storage/app/public/kept.txt', 'must survive');

        $command = $this->command();

        $this->execute($command,'storage:link');

        $this->assertSame(ExitCode::SUCCESS, $this->execute($command,'storage:unlink'));

        $this->assertFalse($this->files->exists($this->link()));
        $this->assertSame('must survive', $this->files->get($this->root . '/storage/app/public/kept.txt'));
    }

    public function test_unlink_leaves_a_real_directory_alone(): void
    {
        $this->files->ensureDirectoryExists($this->link());
        $this->files->put($this->link() . '/someones-file.txt', 'do not delete me');

        $this->assertSame(ExitCode::SUCCESS, $this->execute($this->command(),'storage:unlink'));
        $this->assertSame('do not delete me', $this->files->get($this->link() . '/someones-file.txt'));
    }

    public function test_unlink_is_quiet_when_there_is_nothing_to_remove(): void
    {
        $this->assertSame(ExitCode::SUCCESS, $this->execute($this->command(),'storage:unlink'));
    }

    public function test_both_signatures_are_advertised(): void
    {
        $this->assertArrayHasKey('storage:link', StorageLinkCommand::COMMANDS);
        $this->assertArrayHasKey('storage:unlink', StorageLinkCommand::COMMANDS);
    }
}
