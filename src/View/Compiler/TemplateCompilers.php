<?php

namespace Nitro\View\Compiler;

use Nitro\View\Contracts\TemplateCompiler;

/**
 * Which compiler a template file goes through, decided by its extension.
 *
 * The view layer used to hold exactly one compiler, which is why a template
 * could only ever be Blade. Registering `md` here is what gives a second
 * template language its own compiler without the cache or the engine
 * learning that it exists.
 */
final class TemplateCompilers
{
    /** @var array<string, TemplateCompiler> Keyed by extension, without the dot. */
    private array $compilers = [];

    /**
     * @param TemplateCompiler $default What an unregistered extension compiles through.
     */
    public function __construct(private readonly TemplateCompiler $default)
    {
    }

    /**
     * Register the compiler for one extension, replacing any already there.
     */
    public function register(string $extension, TemplateCompiler $compiler): void
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

        return $matched === null ? $this->default : $this->compilers[$matched];
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
