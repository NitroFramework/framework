<?php

namespace Nitro\Livewire;

use RuntimeException;

/**
 * Resolves and compiles single-file components — a co-located component where
 * an anonymous `new class extends Component { … }` in a leading <?php … ?> block
 * is followed by its Blade view in one file:
 *
 *     <?php
 *     use Nitro\Livewire\Component;
 *     new class extends Component {
 *         public string $message = 'hi';
 *     };
 *     ?>
 *     <div>{{ $message }}</div>
 *
 * The class portion is cached as a requirable PHP file that returns a fresh
 * instance; the view portion is cached under the livewire-sfc:: namespace and
 * rendered as the component's inline view.
 */
class SingleFileComponent
{
    public function __construct(
        protected string $sourceDir,
        protected string $cacheDir,
    ) {}

    /** The cache directory (registered as the livewire-sfc:: view namespace). */
    public function cacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * Locate and compile the single-file component for a name, or null if there
     * is no single-file component (e.g. it's a class-based component instead).
     *
     * @return array{class: string, view: string}|null
     */
    public function resolve(string $name): ?array
    {
        $source = $this->sourceDir . '/' . str_replace('.', '/', $name) . '.blade.php';
        if (! is_file($source)) {
            return null;
        }

        $contents = file_get_contents($source);
        if (! $this->isSingleFile($contents)) {
            return null; // a plain view for a class-based component, not an SFC
        }

        $hash = md5($source);
        $classFile = $this->cacheDir . '/' . $hash . '.class.php';
        $viewFile = $this->cacheDir . '/' . $hash . '.blade.php';

        if (! is_file($classFile) || ! is_file($viewFile) || filemtime($classFile) < filemtime($source)) {
            $this->compile($contents, $classFile, $viewFile);
        }

        return ['class' => $classFile, 'view' => 'livewire-sfc::' . $hash];
    }

    /** Whether file contents declare an anonymous component class (the SFC marker). */
    public function isSingleFile(string $contents): bool
    {
        return (bool) preg_match('/<\?php.*?\bnew\s+(?:#\[[^\]]*\]\s*)?class\b/s', $contents);
    }

    /** Split the class + view portions and cache each in its requirable form. */
    protected function compile(string $contents, string $classFile, string $viewFile): void
    {
        if (! is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }

        if (! preg_match('/<\?php\s*(.*?)\s*\?>(.*)$/s', $contents, $m)) {
            throw new RuntimeException('Single-file component must open with a <?php … ?> class block.');
        }

        // Turn the anonymous `new class` statement into a `return` so requiring
        // the cached file yields a fresh component instance.
        $classBody = preg_replace(
            '/\bnew\s+(#\[[^\]]*\]\s*)?class\b/',
            'return new $1class',
            $m[1],
            1
        );

        file_put_contents($classFile, "<?php\n" . $classBody . "\n");
        file_put_contents($viewFile, trim($m[2]));
    }
}
