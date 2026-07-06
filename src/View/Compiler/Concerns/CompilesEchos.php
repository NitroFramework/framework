<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: {{ }} and {!! !!} echo statements.
 */
trait CompilesEchos
{
    /**
     * Compile both escaped ({{ }}) and raw ({!! !!}) echoes in a single
     * template walk.
     *
     * Two perf shifts vs the old two-pass version:
     *  - one regex sweep instead of two over the same content;
     *  - escaped echoes emit `\nitro_e(...)` (free function) instead of
     *    `$this->e(...)` (method call), which is materially cheaper inside
     *    a page that does hundreds of {{ }} renders. Method dispatch
     *    requires a vtable lookup; free functions are a direct call that
     *    opcache can specialize.
     *
     * Escape syntax `@{{ … }}` / `@{!! … !!}` is preserved — leading `@`
     * strips the directive marker, leaving the literal braces in the
     * output.
     */
    protected function compileEchos(string $content): string
    {
        return preg_replace_callback(
            '/(@)?(\{!!|\{\{)\s*(.+?)\s*(!!\}|\}\})/s',
            static function (array $m): string {
                if (($m[1] ?? '') === '@') {
                    // @{{ … }} → literal {{ … }} in HTML.
                    return substr($m[0], 1);
                }
                return $m[2] === '{!!'
                    ? "<?php echo {$m[3]}; ?>"
                    : "<?php echo \\nitro_e({$m[3]}); ?>";
            },
            $content
        );
    }
}
