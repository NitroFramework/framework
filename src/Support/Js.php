<?php

namespace Nitro\Support;

use JsonSerializable;
use Stringable;

/**
 * Encodes a PHP value as a JavaScript literal safe to embed in a page.
 *
 * The hex flags escape the characters that would otherwise let a value close
 * the surrounding attribute, tag or script block, so `@js($order)` is safe
 * inside `<script>`, inside an `onclick`, and inside a quoted attribute alike.
 */
class Js implements Stringable
{
    /**
     * @param string $js The encoded literal.
     */
    protected function __construct(
        protected string $js,
    ) {}

    /**
     * Encode a value as a JavaScript literal.
     *
     * @param array<string, mixed>|null $options Extra json_encode flags under 'flags'.
     */
    public static function from(mixed $value, ?array $options = null): static
    {
        return new static(static::encode($value, $options['flags'] ?? 0));
    }

    /**
     * Encode a value, resolving anything that knows how to represent itself.
     */
    protected static function encode(mixed $value, int $flags = 0): string
    {
        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif ($value instanceof Stringable) {
            $value = (string) $value;
        }

        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | $flags
        );
    }

    /**
     * Get the encoded literal.
     */
    public function toHtml(): string
    {
        return $this->js;
    }

    public function __toString(): string
    {
        return $this->js;
    }
}
