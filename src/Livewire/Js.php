<?php

namespace Nitro\Livewire;

/**
 * Encodes a PHP value into a safe JavaScript literal for the @js directive and
 * inline scripts, escaping the characters that could break out of a <script>
 * context. @js($order) inside an @script block becomes the JS value directly.
 */
class Js
{
    /** Render a value as a JavaScript literal safe to embed in HTML/JS. */
    public static function from(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
    }
}
