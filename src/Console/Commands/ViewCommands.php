<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PathRegistry;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\View\Blade;

/**
 * Console commands: precompile (view:cache) and clear (view:clear) the compiled template cache.
 */
class ViewCommands implements CommandInterface
{
    /**
     * Clear, explicit dependencies.
     * The Container handles the recursive resolution of these services.
     */
    public function __construct(
        private readonly Blade $view,
        private readonly PathRegistry $paths,
        private readonly ConfigRepository $config,
        private readonly OutputFormatter $output
    ) {}

    public function getCommands(): array
    {
        return [
            'view:cache' => 'Cache all view templates for improved performance',
            'view:clear' => 'Clear view cache'
        ];
    }

    public function handle(string $command, array $arguments): void
    {
        match ($command) {
            'view:cache' => $this->cacheViews(),
            'view:clear' => $this->clearViews(),
            default       => $this->output->error("Unknown view command: {$command}")
        };
    }

    protected function cacheViews(): void
    {
        $this->output->info("Caching views...");

        try {
            $viewsPath = $this->paths->views();

            if (!is_dir($viewsPath)) {
                $this->output->error("Views directory not found at: $viewsPath");
                return;
            }

            $viewFiles = $this->getAllViewFiles($viewsPath);

            if (empty($viewFiles)) {
                $this->output->warning("No view files found to cache.");
                return;
            }

            $cachedCount  = 0;
            $failedCount  = 0;
            $failedViews  = [];

            $this->output->info("Found " . count($viewFiles) . " view files.");
            $this->output->writeln("");

            foreach ($viewFiles as $viewFile) {
                $viewName = $this->getViewNameFromPath($viewFile, $viewsPath);
                try {
                    // Using injected ViewRenderer
                    $this->view->compileOnly($viewName);
                    $cachedCount++;
                    $this->output->writeln($this->output->color("  ✓ Cached: ", 'green') . $viewName);
                } catch (\Exception $e) {
                    $failedCount++;
                    $failedViews[] = ['view' => $viewName, 'error' => $e->getMessage()];
                    $this->output->writeln($this->output->color("  ✖ Failed: ", 'red') . $viewName . " - " . $e->getMessage());
                }
            }

            // ... Summary output logic ...
            $this->printSummary($cachedCount, $failedCount, $failedViews);

            $stats = $this->view->getCacheStats();
            $this->output->writeln("");
            $this->output->writeln($this->output->color("Cache Statistics:", 'blue', true));
            $this->output->writeln("  Path: " . $stats['path']);
            $this->output->writeln("  Total files: " . $stats['files']);
            $this->output->writeln("  Total size: " . $stats['total_size_formatted']);
        } catch (\Exception $e) {
            $this->output->error("Error caching views: " . $e->getMessage());
        }
    }

    protected function clearViews(): void
    {
        $this->output->info("Clearing view cache...");

        try {
            $statsBefore   = $this->view->getCacheStats();
            $filesBefore   = $statsBefore['files'];
            $sizeBefore    = $statsBefore['total_size_formatted'];

            $this->view->clearCache();

            if ($filesBefore > 0) {
                $this->output->success("Cleared {$filesBefore} cache files ({$sizeBefore}).");
            } else {
                $this->output->warning("No view cache found to clear.");
            }
            // ... Final output formatting ...
        } catch (\Exception $e) {
            $this->output->error("Error clearing view cache: " . $e->getMessage());
        }
    }

    protected function getAllViewFiles(string $directory): array
    {
        $viewFiles = [];
        $extension = $this->config->get('view.extension');
        $iterator  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $extension)) {
                $viewFiles[] = $file->getPathname();
            }
        }

        return $viewFiles;
    }

    protected function getViewNameFromPath(string $filePath, string $viewsPath): string
    {
        $extension    = $this->config->get('view.extension');
        $relativePath = str_replace($viewsPath . DIRECTORY_SEPARATOR, '', $filePath);
        $relativePath = str_replace('.' . $extension, '', $relativePath);
        return str_replace(DIRECTORY_SEPARATOR, '.', $relativePath);
    }
}
