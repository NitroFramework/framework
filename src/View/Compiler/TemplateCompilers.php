<?php

namespace Nitro\View\Compiler;

use Closure;
use Nitro\View\Contracts\TemplateCompiler;

/**
 * Which compiler a template file goes through, decided by its extension.
 *
 * The view layer used to hold exactly one compiler, which is why a template
 * could only ever be Blade. Registering `md` here is what gives a second
 * template language its own compiler without the cache or the engine
 * learning that it exists.
 *
 * A compiler may be given as a closure, and should be: knowing which
 * extensions exist is not a reason to build the things that compile them, and
 * a render served from the compiled cache never compiles anything at all.
 */
final class TemplateCompilers
{
    /** @var array<string, Closure|TemplateCompiler> Keyed by extension, without the dot. */
    private array $compilers = [];

    /**
     * @param Closure|TemplateCompiler $default What an unregistered extension compiles through.
     */
    public function __construct(private Closure|TemplateCompiler $default)
    {
    }

    /**
     * Register the compiler for one extension, replacing any already there.
     */
    public function register(string $extension, Closure|TemplateCompiler $compiler): void
    {
        $this->compilers[strtolower(ltrim($extension, '.'))] = $compiler;
    }

    /**
     * The compiler for a template file.
     *
     * The longest registered extension wins, so `blade.php` is not shadowed by
     * a compiler registered for `php`.
     */
    public function for(string $templateFile): TemplateCompiler
    {
        $file    = strtolower($templateFile);
        $matched = null;

        foreach (array_keys($this->compilers) as $extension) {
            if (! str_ends_with($file, '.' . $extension)) {
                continue;
            }

            if ($matched === null || strlen($extension) > strlen($matched)) {
                $matched = $extension;
            }
        }

        if ($matched === null) {
            return $this->default = $this->build($this->default);
        }

        return $this->compilers[$matched] = $this->build($this->compilers[$matched]);
    }

    /** Build a compiler given as a closure, once. */
    private function build(Closure|TemplateCompiler $compiler): TemplateCompiler
    {
        return $compiler instanceof Closure ? $compiler() : $compiler;
    }

    /**
     * Every registered extension, longest first.
     *
     * @return array<int, string>
     */
    public function extensions(): array
    {
        $extensions = array_keys($this->compilers);

        usort($extensions, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $extensions;
    }
}
