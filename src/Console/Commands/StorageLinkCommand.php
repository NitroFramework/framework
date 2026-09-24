<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\ExitCode;
use Nitro\Console\OutputFormatter;
use Nitro\Filesystem\Filesystem;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Contracts\PathRegistry;
use Throwable;

/**
 * Connect the public disk to somewhere the web server will serve it from.
 *
 * A 'public' disk is rooted at storage/app/public and carries a url of
 * APP_URL/storage, so url() answers /storage/avatars/1.png. Nothing serves
 * that path until public/storage points at the disk's root — the configuration
 * describes a URL the application cannot honour on its own, and every upload
 * comes back 404 with nothing in the logs to say why.
 *
 * Which links to make is read from config('filesystems.links'), so a disk kept
 * somewhere else can say where it wants to appear.
 */
class StorageLinkCommand implements CommandInterface
{
    public function __construct(
        private PathRegistry $paths,
        private ConfigRepository $config,
        private Filesystem $files,
        private OutputFormatter $output,
    ) {}

    /** @var array<string, string> */
    public const COMMANDS = [
        'storage:link' => 'Create the symbolic links configured for the application',
        'storage:unlink' => 'Remove the symbolic links configured for the application',
    ];

    public function getCommands(): array
    {
        return self::COMMANDS;
    }

    public function handle(string $signature, array $arguments): int
    {
        return $signature === 'storage:unlink'
            ? $this->unlink()
            : $this->link(
                relative: in_array('--relative', $arguments, true),
                force: in_array('--force', $arguments, true),
            );
    }

    protected function link(bool $relative, bool $force): int
    {
        $status = ExitCode::SUCCESS;

        foreach ($this->links() as $link => $target) {
            if ($this->files->exists($link)) {
                // Only a link is ours to replace. A real directory at that path
                // holds someone's files, and removing it to make a link would
                // be the command deleting data it was not asked to touch.
                if (! $this->files->isLink($link)) {
                    $this->output->error("The [{$link}] path already exists and is not a link.");

                    $status = ExitCode::FAILURE;

                    continue;
                }

                if (! $force) {
                    $this->output->warning("The [{$link}] link already exists. Use --force to recreate it.");

                    continue;
                }

                $this->remove($link);
            }

            $this->files->ensureDirectoryExists(dirname($link));

            try {
                $made = $relative
                    ? $this->files->relativeLink($target, $link)
                    : $this->files->link($target, $link);
            } catch (Throwable $e) {
                $this->output->error($e->getMessage());

                $status = ExitCode::FAILURE;

                continue;
            }

            if (! $made) {
                $this->output->error("Could not link [{$link}] to [{$target}].");

                $status = ExitCode::FAILURE;

                continue;
            }

            $this->output->success("The [{$link}] link has been connected to [{$target}].");
        }

        return $status;
    }

    protected function unlink(): int
    {
        foreach (array_keys($this->links()) as $link) {
            if (! $this->files->exists($link)) {
                continue;
            }

            if (! $this->files->isLink($link)) {
                $this->output->warning("The [{$link}] path is not a link, so it was left alone.");

                continue;
            }

            $this->remove($link);

            $this->output->success("The [{$link}] link has been removed.");
        }

        return ExitCode::SUCCESS;
    }

    /**
     * Remove a link without touching what it points at.
     *
     * Which call does it depends on what is on the other end, and on the
     * platform: a junction goes with rmdir, a file symlink with unlink.
     */
    protected function remove(string $link): void
    {
        if (! @rmdir($link)) {
            @unlink($link);
        }
    }

    /**
     * The links this application wants, as link path => target path.
     *
     * @return array<string, string>
     */
    protected function links(): array
    {
        $configured = $this->config->get('filesystems.links');

        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return [
            $this->paths->public('storage') => $this->paths->storage('app/public'),
        ];
    }
}
