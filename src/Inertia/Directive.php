<?php

namespace Nitro\Inertia;

/**
 * The `@inertia` Blade directive.
 *
 * Emits the mount point and the page object beside it, in the exact markup
 * the client library looks for: a script of type application/json whose
 * data-page attribute names the element to mount on, with the page as its
 * text content. The shape is not ours to choose — the client reads it.
 *
 * The page travels as script content rather than an element attribute because
 * a JSON document in an attribute must survive entity encoding in both
 * directions, and a prop containing quotes or angle brackets is where that
 * comes apart.
 */
class Directive
{
    /**
     * Compile `@inertia` or `@inertia('root')`.
     *
     * The expression, when given, names the element id to mount on.
     */
    public static function compile(string $expression = ''): string
    {
        $id = trim(trim($expression), "'\"") ?: 'app';

        /*
         * JSON_HEX_TAG escapes < and > so a prop holding markup cannot close
         * the script tag early and have its contents parsed as elements.
         */
        return '<?php echo \'<script data-page="' . $id . '" type="application/json">\''
            . ' . json_encode($page, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)'
            . ' . \'</script><div id="' . $id . '"></div>\'; ?>';
    }

    /**
     * Compile `@inertiaHead`.
     *
     * A no-op without server-side rendering: there is no head content until
     * something has rendered the page on the server. Defined anyway so a root
     * template written against the documented shape still compiles.
     */
    public static function compileHead(string $expression = ''): string
    {
        return '';
    }
}
