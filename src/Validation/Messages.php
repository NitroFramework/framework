<?php

namespace Nitro\Validation;

/**
 * The messages an application supplies in place of a rule's own wording.
 *
 * Each rule class carries the sentence it fails with, so this holds only the
 * overrides. A key may name a field and a rule ('email.required'), a rule
 * alone ('required'), or a field alone ('email'), and may contain a wildcard
 * so one line covers every entry of a list.
 */
class Messages
{
    /** @var array<string, string> */
    protected array $messages;

    /** @param array<string, string> $customMessages */
    public function __construct(array $customMessages = [])
    {
        $this->messages = $customMessages;
    }

    /**
     * The message an application gave for this field and rule, if any.
     *
     * Most specific first: a line written for one rule on one field beats one
     * written for the rule everywhere, which beats one written for the field.
     */
    public function resolve(string $attribute, string $rule): ?string
    {
        foreach (["{$attribute}.{$rule}", $rule, $attribute] as $wanted) {
            foreach ($this->messages as $key => $message) {
                if (self::keyMatches($key, $wanted)) {
                    return $message;
                }
            }
        }

        return null;
    }

    /**
     * Whether a key covers a wanted one, treating '*' as one path segment.
     *
     * 'items.*.qty.integer' has to reach 'items.0.qty.integer' without also
     * reaching 'items.0.lines.0.qty.integer', which is why the wildcard stops
     * at a dot.
     */
    public static function keyMatches(string $key, string $wanted): bool
    {
        if ($key === $wanted) {
            return true;
        }

        if (! str_contains($key, '*')) {
            return false;
        }

        $pattern = str_replace('\*', '[^.]*', preg_quote($key, '#'));

        return preg_match('#^' . $pattern . '$#u', $wanted) === 1;
    }

    /** A message by exact key. */
    public function get(string $key, string $default = ''): string
    {
        return $this->messages[$key] ?? $default;
    }

    public function set(string $key, string $message): void
    {
        $this->messages[$key] = $message;
    }

    /** @param array<string, string> $messages */
    public function setMultiple(array $messages): void
    {
        $this->messages = array_merge($this->messages, $messages);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->messages;
    }
}
