<?php

namespace Nitro\View\Compiler;

use Nitro\View\Contracts\TemplateCompiler;
use Nitro\View\Contracts\TagCompiler;


/**
 * Compiles Blade templates to plain PHP, delegating each syntax to the Compiles* concerns.
 */
class BladeCompiler implements TemplateCompiler
{
    use Concerns\CompilesComments,
        Concerns\CompilesEchos,
        Concerns\CompilesConditionals,
        Concerns\CompilesLoops,
        Concerns\CompilesLayouts,
        Concerns\CompilesStacks,
        Concerns\CompilesIncludes,
        Concerns\CompilesComponents,
        Concerns\CompilesHelpers,
        Concerns\CompilesRawPhp,
        Concerns\CompilesFragments,
        Concerns\CompilesInjections,
        Concerns\CompilesMiscellaneous,
        Concerns\CompilesStream;

    protected static array $customDirectives = [];

    /** Callbacks that transform raw template source before any compilation pass. */
    protected static array $precompilers = [];

    protected array $verbatimBlocks = [];
    protected array $footer = [];

    public function __construct(
        private readonly TagCompiler $tagCompiler,
    ) {}

    public function compile(string $content): string
    {
        $this->footer = [];
        $this->forElseCounter = 0;

        foreach (self::$precompilers as $precompiler) {
            $content = $precompiler($content);
        }

        $content = $this->compileComments($content);

        $result = '';
        foreach (token_get_all($content) as $token) {
            $result .= is_array($token) ? $this->parseToken($token) : $token;
        }

        $result = $this->restoreVerbatimBlocks($result);

        // Append footer if anything was pushed (future use)
        if (!empty($this->footer)) {
            $result .= implode("\n", $this->footer);
        }

        return $result;
    }

    protected function parseToken(array $token): string
    {
        [$id, $content] = $token;

        if ($id === T_INLINE_HTML) {
            return $this->compileInlineHtml($content);
        }

        return $content;
    }

    protected function compileInlineHtml(string $content): string
    {
        $content = $this->storeVerbatimBlocks($content);
        $content = $this->compileComments($content);
        $content = $this->tagCompiler->compile($content);
        $content = $this->compileEchos($content);
        $content = $this->compileDirectives($content);

        return $content;
    }

    protected function storeVerbatimBlocks(string $content): string
    {
        return preg_replace_callback(
            '/@verbatim\s*(.*?)\s*@endverbatim/s',
            function ($matches) {
                $placeholder = '___VERBATIM_' . md5(uniqid('', true)) . '___';
                $this->verbatimBlocks[$placeholder] = $matches[1];
                return $placeholder;
            },
            $content
        );
    }

    protected function restoreVerbatimBlocks(string $content): string
    {
        foreach ($this->verbatimBlocks as $placeholder => $original) {
            $content = str_replace($placeholder, $original, $content);
        }
        $this->verbatimBlocks = [];
        return $content;
    }

    // ================================================================
    // DIRECTIVE COMPILATION (Laravel's robust approach)
    // ================================================================

    protected function compileDirectives(string $content): string
    {
        preg_match_all(
            '/\B@(@?\w+(?:::\w+)?)([ \t]*)(\( ( [\S\s]*? ) \))?/x',
            $content,
            $matches
        );

        $offset = 0;

        for ($i = 0; isset($matches[0][$i]); $i++) {
            $match = [
                $matches[0][$i],
                $matches[1][$i],
                $matches[2][$i],
                $matches[3][$i] ?: null,
                $matches[4][$i] ?: null,
            ];

            while (
                isset($match[4]) &&
                str_ends_with($match[0], ')') &&
                !$this->hasEvenNumberOfParentheses($match[0])
            ) {
                $afterPos = strpos($content, $match[0], $offset);
                if ($afterPos === false) {
                    break;
                }

                $after = substr($content, $afterPos + strlen($match[0]));
                if ($after === '' || $after === false) {
                    break;
                }

                $nextParen = strpos($after, ')');
                if ($nextParen === false) {
                    break;
                }

                $rest = substr($after, 0, $nextParen);

                if (isset($matches[0][$i + 1]) && str_contains($rest . ')', $matches[0][$i + 1])) {
                    unset($matches[0][$i + 1]);
                    $i++;
                }

                $match[0] = $match[0] . $rest . ')';
                $match[3] = $match[3] . $rest . ')';
                $match[4] = $match[4] . $rest;
            }

            [$content, $offset] = $this->replaceFirstStatement(
                $match[0],
                $this->compileDirective($match),
                $content,
                $offset
            );
        }

        return $content;
    }

    protected function compileDirective(array $match): string
    {
        // Handle @@directive escape — strip one @ and return literal
        if (str_contains($match[1], '@')) {
            return isset($match[3]) ? $match[1] . $match[3] : $match[1];
        }

        $directiveName = $match[1];
        $arguments = $match[3] ?? '';

        $method = 'compile' . ucfirst($directiveName);

        if (method_exists($this, $method)) {
            $compiled = $this->$method($arguments);
            return isset($match[3]) ? $compiled : $compiled . $match[2];
        }

        if (isset(static::$customDirectives[$directiveName])) {
            $args = $arguments;
            if (str_starts_with($args, '(') && str_ends_with($args, ')')) {
                $args = substr($args, 1, -1);
            }
            $compiled = static::$customDirectives[$directiveName](trim($args));
            return isset($match[3]) ? $compiled : $compiled . $match[2];
        }

        return $match[0];
    }

    protected function replaceFirstStatement(string $search, string $replace, string $subject, int $offset): array
    {
        if ($search === '') {
            return [$subject, $offset];
        }

        $position = strpos($subject, $search, $offset);

        if ($position !== false) {
            return [
                substr_replace($subject, $replace, $position, strlen($search)),
                $position + strlen($replace),
            ];
        }

        return [$subject, 0];
    }

    protected function hasEvenNumberOfParentheses(string $expression): bool
    {
        $tokens = token_get_all('<?php ' . $expression);

        $last = end($tokens);
        if ($last !== ')') {
            return false;
        }

        $opening = 0;
        $closing = 0;

        foreach ($tokens as $token) {
            if ($token === ')') {
                $closing++;
            } elseif ($token === '(') {
                $opening++;
            }
        }

        return $opening === $closing;
    }

    // ================================================================
    // REGISTRATION
    // ================================================================

    public static function registerCustomDirective(string $name, callable $callback): void
    {
        static::$customDirectives[$name] = $callback;
    }

    /**
     * Register a precompiler — a callback that rewrites raw template source
     * before any compilation pass (e.g. to expand custom tags into directives).
     */
    public static function registerPrecompiler(callable $callback): void
    {
        static::$precompilers[] = $callback;
    }

    public static function getCustomDirectives(): array
    {
        return static::$customDirectives;
    }

    /** Reset the in-process directive registry (test harnesses). */
    public static function clearCustomDirectives(): void
    {
        static::$customDirectives = [];
    }
}

