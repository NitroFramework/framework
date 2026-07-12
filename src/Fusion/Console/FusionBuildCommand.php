<?php

namespace Nitro\Fusion\Console;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PathRegistry;
use Nitro\Fusion\Attributes\Client;
use Nitro\Fusion\Build\Builder;
use Nitro\Fusion\Build\FusionBuildException;
use ReflectionClass;

/**
 * `php nitro fusion:build` — discovers `#[Client]` components under app/Components,
 * transpiles each (Pure-UI → JS, #[Server] → RPC stub, purity enforced) and
 * compiles its co-located Blade view, then writes a single browser bundle to
 * public/nitro/fusion-app.js and copies the Fusion runtime alongside it.
 *
 *   app/Components/Counter.php        (the #[Client] component)
 *   app/Components/Counter.blade.php  (its reactive view)
 */
class FusionBuildCommand implements CommandInterface
{
    public function __construct(
        private PathRegistry $paths,
        private OutputFormatter $output,
    ) {
    }

    public function getCommands(): array
    {
        return [
            'fusion:build' => 'Transpile #[Client] components to a browser bundle',
        ];
    }

    public function handle(string $command, array $arguments): void
    {
        $dir = $this->paths->base('app' . DIRECTORY_SEPARATOR . 'Fusion' . DIRECTORY_SEPARATOR . 'Components');
        if (! is_dir($dir)) {
            $this->output->writeln($this->output->color("  No components directory at {$dir}", 'yellow'));
            return;
        }

        $builder = new Builder();
        $artifacts = [];

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $class = 'App\\Fusion\\Components\\' . $name;

            if (! class_exists($class)) {
                continue;
            }
            if ((new ReflectionClass($class))->getAttributes(Client::class) === []) {
                continue; // not a #[Client] component — leave it to server rendering
            }

            $view = $dir . DIRECTORY_SEPARATOR . $name . '.blade.php';
            if (! is_file($view)) {
                $this->output->writeln($this->output->color("  ⚠ {$name}: no view {$name}.blade.php — skipped", 'yellow'));
                continue;
            }

            try {
                $artifacts[] = $builder->compileComponent(
                    $name,
                    (string) file_get_contents($file),
                    (string) file_get_contents($view),
                );
                $this->output->writeln("  " . $this->output->color('✓', 'green') . " compiled {$name}");
            } catch (FusionBuildException $e) {
                $this->output->writeln($this->output->color($e->getMessage(), 'red'));
                return;
            }
        }

        $outDir = $this->paths->public('nitro');
        if (! is_dir($outDir)) {
            @mkdir($outDir, 0755, true);
        }

        file_put_contents($outDir . DIRECTORY_SEPARATOR . 'fusion-app.js', $builder->bundle($artifacts));

        // Ship the runtime + the global nav-progress script from src/Fusion/dist.
        $distDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dist';
        foreach (['fusion.js', 'fusion-nprogress.js'] as $asset) {
            $src = $distDir . DIRECTORY_SEPARATOR . $asset;
            if (is_file($src)) {
                copy($src, $outDir . DIRECTORY_SEPARATOR . $asset);
            }
        }

        $this->output->writeln("");
        $this->output->writeln(
            "  " . $this->output->color('Fusion', 'cyan', true)
            . " built " . count($artifacts) . " component(s) → public/nitro/fusion-app.js"
        );
    }
}
