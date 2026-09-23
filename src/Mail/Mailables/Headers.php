<?php

namespace Nitro\Mail\Mailables;

/**
 * Headers a message carries beyond the ones the envelope implies.
 *
 * Message-Id and References are what thread a reply to what it answers,
 * so a mail client files it in the conversation rather than on its own.
 */
class Headers
{
    /**
     * @param array<int, string>      $references Message ids this answers.
     * @param array<string, string>   $text       Any other header, by name.
     */
    public function __construct(
        public ?string $messageId = null,
        public array $references = [],
        public array $text = [],
    ) {}

    public function messageId(string $messageId): static
    {
        $this->messageId = $messageId;

        return $this;
    }

    /** @param array<int, string> $references */
    public function references(array $references): static
    {
        $this->references = array_merge($this->references, $references);

        return $this;
    }

    /** @param array<string, string> $text */
    public function text(array $text): static
    {
        $this->text = array_merge($this->text, $text);

        return $this;
    }

    /** The References header's value, each id in angle brackets. */
    public function referencesString(): string
    {
        return implode(' ', array_map(
            static fn (string $id): string => '<' . trim($id, '<>') . '>',
            $this->references,
        ));
    }
}
