<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: helper directives such as @csrf and @method.
 */
trait CompilesHelpers
{
    protected function compileCsrf(string $args): string
    {
        // Delegate to csrf_field()/csrf_token() (security.php) so the token is
        // minted on demand from a single CSPRNG source. Reading $_SESSION["_csrf"]
        // raw here emitted an empty token whenever nothing had minted one yet.
        return '<?php echo csrf_field(); ?>';
    }

    protected function compileMethod(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo '<input type=\"hidden\" name=\"_method\" value=\"' . htmlspecialchars({$expression}, ENT_QUOTES, 'UTF-8') . '\">'; ?>";
    }

    protected function compileJson(string $args): string
    {
        // Pass through: @json($data) or @json($data, JSON_PRETTY_PRINT)
        $expression = $this->stripParentheses($args);

        return "<?php echo json_encode({$expression}); ?>";
    }

   

    protected function compileDump(string $args): string
    {
        $expression = $this->stripParentheses($args);
        $parts = $this->splitLastArgument($expression);

        if ($parts) {
            return "<?php (new \Nitro\Debug\Dumper({$parts['last']}))->dump({$parts['rest']}); ?>";
        }

        return "<?php (new \Nitro\Debug\Dumper())->dump({$expression}); ?>";
    }

    protected function compileDd(string $args): string
    {
        $expression = $this->stripParentheses($args);
        return "<?php (new \Nitro\Debug\Dumper())->dump({$expression}); exit(1); ?>";
    }

    protected function compileRawdump(string $args): string
    {
        $expression = $this->stripParentheses($args);
        return "<?php var_dump({$expression}); ?>";
    }

    private function splitLastArgument(string $expression): ?array
    {
        $depth = 0;
        $lastComma = null;

        for ($i = 0; $i < strlen($expression); $i++) {
            $char = $expression[$i];
            if ($char === '(' || $char === '[' || $char === '{') $depth++;
            elseif ($char === ')' || $char === ']' || $char === '}') $depth--;
            elseif ($char === ',' && $depth === 0) $lastComma = $i;
        }

        if ($lastComma === null) return null;

        $rest = trim(substr($expression, 0, $lastComma));
        $last = trim(substr($expression, $lastComma + 1));

        if (!ctype_digit($last)) return null;

        return ['rest' => $rest, 'last' => $last];
    }

    protected function compileAsset(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo '/' . ltrim({$expression}, '/'); ?>";
    }

    protected function compileUrl(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo {$expression}; ?>";
    }

    protected function compileRoute(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo {$expression}; ?>";
    }

    protected function compileChecked(string $args): string
    {
        return "<?php if{$args}: echo 'checked'; endif; ?>";
    }

    protected function compileSelected(string $args): string
    {
        return "<?php if{$args}: echo 'selected'; endif; ?>";
    }

    protected function compileDisabled(string $args): string
    {
        return "<?php if{$args}: echo 'disabled'; endif; ?>";
    }

    protected function compileReadonly(string $args): string
    {
        return "<?php if{$args}: echo 'readonly'; endif; ?>";
    }

    protected function compileRequired(string $args): string
    {
        return "<?php if{$args}: echo 'required'; endif; ?>";
    }

    protected function compileClass(string $args): string
    {
        $expression = !empty($args) ? $args : '([])';

        return "class=\"<?php echo \$this->toCssClasses{$expression}; ?>\"";
    }

    protected function compileStyle(string $args): string
    {
        $expression = !empty($args) ? $args : '([])';

        return "style=\"<?php echo \$this->toCssStyles{$expression}; ?>\"";
    }
}
