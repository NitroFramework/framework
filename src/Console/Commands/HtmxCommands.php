<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PathRegistry;

/**
 * Console commands: publish the htmx config and scaffold HTMX components (make:htmx).
 */
class HtmxCommands implements CommandInterface
{
    public function __construct(
        private PathRegistry $paths,
        private OutputFormatter $output,
    ) {}

    public function getCommands(): array
    {
        return [
            'htmx:publish' => 'Publish the framework htmx config to config/htmx.php',
            'make:htmx'    => 'Scaffold a new HTMX component (class + view)',
        ];
    }

    public function handle(string $command, array $arguments): void
    {
        match ($command) {
            'htmx:publish' => $this->publishConfig($arguments),
            'make:htmx'    => $this->makeComponent($arguments),
            default        => $this->output->error("Unknown htmx command: {$command}"),
        };
    }

    protected function publishConfig(array $arguments): void
    {
        $force  = in_array('--force', $arguments, true) || in_array('-f', $arguments, true);
        $paths  = $this->paths;
        $target = $paths->config('htmx.php');
        $source = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Htmx' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'htmx.php';

        if (!is_file($source)) {
            $this->output->error("Source config not found: {$source}");
            return;
        }

        if (is_file($target) && !$force) {
            $this->output->warning("config/htmx.php already exists. Re-run with --force to overwrite.");
            return;
        }

        $configDir = dirname($target);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        if (!copy($source, $target)) {
            $this->output->error("Failed to copy config to {$target}");
            return;
        }

        $this->output->success("Published htmx config to config/htmx.php");
    }

    /**
     * make:htmx Counter
     *   → app/Htmx/Components/Counter.php
     *   → resources/views/components/htmx/counter.blade.php
     *
     * --force overwrites existing files.
     */
    protected function makeComponent(array $arguments): void
    {
        $name = $arguments[0] ?? null;
        if (!$name) {
            $this->output->error("Usage: php nitro make:htmx <ComponentName>");
            return;
        }

        // Normalize — accept counter, Counter, command-palette, CommandPalette
        $class = $this->toClassName($name);
        $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $class));

        $force = in_array('--force', $arguments, true) || in_array('-f', $arguments, true);
        $paths = $this->paths;

        $componentFile = $paths->base('app' . DIRECTORY_SEPARATOR . 'Htmx' . DIRECTORY_SEPARATOR . 'Components' . DIRECTORY_SEPARATOR . $class . '.php');
        $viewFile      = $paths->resources('views' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'htmx' . DIRECTORY_SEPARATOR . $kebab . '.blade.php');

        if (is_file($componentFile) && !$force) {
            $this->output->warning("Component already exists: {$componentFile}. Re-run with --force to overwrite.");
            return;
        }

        $this->ensureDirectory(dirname($componentFile));
        $this->ensureDirectory(dirname($viewFile));

        file_put_contents($componentFile, $this->componentStub($class));
        $this->output->success("Created component: app/Htmx/Components/{$class}.php");

        if (!is_file($viewFile) || $force) {
            file_put_contents($viewFile, $this->viewStub(lcfirst($class), $class));
            $this->output->success("Created view: resources/views/components/htmx/{$kebab}.blade.php");
        } else {
            $this->output->warning("View already exists: {$viewFile} (kept as-is)");
        }
    }

    private function toClassName(string $input): string
    {
        // Insert a separator at camelCase boundaries so "ScratchDemo" and
        // "scratchDemo" both round-trip to "ScratchDemo" — without this,
        // a strtolower would collapse them into "Scratchdemo".
        $separated = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $input);
        $words     = array_filter(preg_split('/[^A-Za-z0-9]+/', $separated));
        return implode('', array_map(
            static fn(string $w) => ucfirst(strtolower($w)),
            $words,
        ));
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function componentStub(string $class): string
    {
        return <<<PHP
        <?php

        namespace App\Htmx\Components;

        use Nitro\Htmx\HtmxComponent;

        class {$class} extends HtmxComponent
        {
            // Add public properties for state. They auto-persist + auto-render.
            //   public int \$count = 0;

            // Add #[Modelable] to any property bound from a hx-model input:
            //   #[\Nitro\Htmx\Attributes\Modelable]
            //   public string \$query = '';

            // Action methods just mutate properties — no render() call needed.
            //   public function increment(): void {
            //       \$this->count++;
            //   }
        }
        PHP;
    }

    private function viewStub(string $compName, string $class): string
    {
        return <<<BLADE
        <div hx-component="{$compName}">
            <h2>{$class}</h2>
            {{-- Auto-rendered on every action. Public properties are available as variables. --}}
        </div>
        BLADE;
    }

}
