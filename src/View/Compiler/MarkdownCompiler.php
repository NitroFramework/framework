<?php

namespace Nitro\View\Compiler;

use Nitro\View\Contracts\TemplateCompiler;
use Nitro\View\Markdown\FrontMatter;

/**
 * Compiles a `.md` template by wrapping the Blade compiler rather than
 * replacing it, so `{{ $title }}`, `@if` and components keep working inside a
 * Markdown page.
 *
 * The conversion happens at render time, on the finished output, which is what
 * lets a directive emit Markdown and still have it become HTML. Code spans and
 * fenced blocks are lifted out first: a page documenting Blade syntax has to be
 * able to print `{{ $x }}` without the compiler executing it.
 */
final class MarkdownCompiler implements TemplateCompiler
{
    /** The section a page's body fills when its front matter names no other. */
    private const DEFAULT_SECTION = 'content';

    public function __construct(private readonly TemplateCompiler $blade)
    {
    }

    /**
     * Compile Markdown source into executable PHP.
     */
    public function compile(string $content): string
    {
        [$matter, $body] = FrontMatter::split($content);

        $held = [];
        $body = $this->holdCode(str_replace(["\r\n", "\r"], "\n", $body), $held);

        $source = $this->prelude($matter) . $this->wrap($body, $matter);

        return $this->release($this->preserveNewlines($this->blade->compile($source)), $held);
    }

    /**
     * Keep the newline PHP swallows after a closing tag, where Markdown needs it.
     *
     * PHP drops the one newline that follows `?>`. HTML does not care; Markdown
     * does — a list whose items lost their line endings is one run-on
     * paragraph. But a block directive occupies a whole line of the source and
     * should leave nothing behind, and restoring that newline too would put a
     * blank line between every item and make the list loose.
     *
     * So the newline comes back only when the closing tag sits on a line that
     * rendered something: text before it, or an echo that stands for text.
     */
    private function preserveNewlines(string $compiled): string
    {
        $tokens = preg_split('/(<\?php|<\?=|\?>)/', $compiled, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($tokens === false) {
            return $compiled;
        }

        $result    = '';
        $inPhp     = false;
        $afterTag  = false;
        $renders   = false;

        foreach ($tokens as $token) {
            if ($token === '<?php' || $token === '<?=') {
                $renders = $token === '<?=';
                $inPhp   = true;
                $result .= $token;
                continue;
            }

            if ($token === '?>') {
                $inPhp    = false;
                $afterTag = true;
                $result  .= $token;
                continue;
            }

            if ($inPhp) {
                $renders = $renders || preg_match('/\b(?:echo|print)\b/', $token) === 1;
                $result .= $token;
                continue;
            }

            if ($afterTag && $renders && str_starts_with($token, "\n")) {
                $result .= "\n";
            }

            $afterTag = false;
            $result  .= $token;

            $tail    = strrchr($token, "\n");
            $renders = $tail === false
                ? $renders || trim($token) !== ''
                : trim($tail) !== '';
        }

        return $result;
    }

    /**
     * Declare the front matter as variables, and hand it to the engine so the
     * layout a page extends can read the title the page declared.
     *
     * Values already passed to the view win: a controller computing a title
     * knows more than the file does.
     *
     * @param array<string, mixed> $matter
     */
    private function prelude(array $matter): string
    {
        if ($matter === []) {
            return '';
        }

        return "<?php\n"
            . '$__matter = ' . var_export($matter, true) . ";\n"
            . 'foreach ($__matter as $__key => $__value) { if (! isset($$__key)) { $$__key = $__value; } }' . "\n"
            . '$this->pageData($__matter);' . "\n"
            . 'unset($__matter, $__key, $__value);' . "\n"
            . "?>\n";
    }

    /**
     * Buffer the body, convert what it rendered, and put it in a section when
     * the page extends a layout.
     *
     * @param array<string, mixed> $matter
     */
    private function wrap(string $body, array $matter): string
    {
        $converted = "<?php ob_start(); ?>\n"
            . $body
            . "\n<?php echo \\Nitro\\View\\Markdown\\Markdown::render((string) ob_get_clean()); ?>";

        $extends = $matter['extends'] ?? null;

        if (! is_string($extends) || $extends === '') {
            return $converted;
        }

        $section = $matter['section'] ?? self::DEFAULT_SECTION;
        $section = is_string($section) && $section !== '' ? $section : self::DEFAULT_SECTION;

        return "@extends('" . addslashes($extends) . "')\n"
            . "@section('" . addslashes($section) . "')\n"
            . $converted
            . "\n@endsection\n";
    }

    /**
     * Lift fenced blocks and code spans out of reach of the Blade compiler.
     *
     * @param array<int, string> $held
     */
    private function holdCode(string $body, array &$held): string
    {
        $patterns = [
            '/^([ \t]{0,3})(`{3,}|~{3,})[^\n]*\n[\s\S]*?(?:^[ \t]{0,3}\2[ \t]*$|\z)/m',
            '/(?<!`)(`+)(?!`)([^\n]+?)(?<!`)\1(?!`)/',
        ];

        foreach ($patterns as $pattern) {
            $body = preg_replace_callback(
                $pattern,
                static function (array $match) use (&$held): string {
                    $held[] = $match[0];

                    return "\x02md" . (count($held) - 1) . "\x03";
                },
                $body
            ) ?? $body;
        }

        return $body;
    }

    /**
     * Put each held fragment back as a literal echo, so what the compiler was
     * kept away from the renderer cannot execute either.
     *
     * Runs after {@see preserveNewlines()}, because a fragment may itself quote
     * a closing tag and must not be rewritten. It carries its own trailing
     * newline for PHP to swallow instead of the document's.
     *
     * @param array<int, string> $held
     */
    private function release(string $compiled, array $held): string
    {
        if ($held === []) {
            return $compiled;
        }

        return preg_replace_callback(
            '/\x02md(\d+)\x03/',
            static function (array $match) use ($held): string {
                $literal = $held[(int) $match[1]] ?? '';

                return "<?php echo '" . strtr($literal, ['\\' => '\\\\', "'" => "\\'"]) . "'; ?>\n";
            },
            $compiled
        ) ?? $compiled;
    }
}
