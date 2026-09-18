<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: {{ }} and {!! !!} echo statements.
 */
trait CompilesEchos
{
    /**
     * Compile escaped `{{ }}` and raw `{!! !!}` echoes in one pass.
     *
     * Escaped echoes emit the free function \nitro_e() rather than a method
     * call, which matters on a page doing hundreds of them. A leading `@`
     * escapes the braces into literal output.
     */
    protected function compileEchos(string $content): string
    {
        return preg_replace_callback(
            '/(@)?(\{!!|\{\{)\s*(.+?)\s*(!!\}|\}\})/s',
            static function (array $matches): string {
                if (($matches[1] ?? '') === '@') {
                    return substr($matches[0], 1);
                }
                return $matches[2] === '{!!'
                    ? "<?php echo {$matches[3]}; ?>"
                    : "<?php echo \\nitro_e({$matches[3]}); ?>";
            },
            $content
        );
    }
}
