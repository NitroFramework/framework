<?php

namespace Nitro\Notifications\Messages;

use Nitro\Notifications\Action;

/**
 * The body of a notification, described rather than written.
 *
 * Lines before the action and lines after it are kept apart, so a channel can
 * render them in the right order without the notification having to know how
 * that channel lays a message out.
 */
class SimpleMessage
{
    /** One of 'success', 'error' or 'info'; steers how a channel styles it. */
    public string $level = 'info';

    /** The heading, or null to let the channel choose one. */
    public ?string $subject = null;

    /** The opening line, or null for the channel's default. */
    public ?string $greeting = null;

    /** The closing line, or null for the channel's default. */
    public ?string $salutation = null;

    /** @var array<int, string> Lines before the action. */
    public array $introLines = [];

    /** @var array<int, string> Lines after the action. */
    public array $outroLines = [];

    /** The call to action, when there is one. */
    public ?Action $action = null;

    /**
     * Mark the message as reporting success.
     */
    public function success(): static
    {
        $this->level = 'success';

        return $this;
    }

    /**
     * Mark the message as reporting a failure.
     */
    public function error(): static
    {
        $this->level = 'error';

        return $this;
    }

    /**
     * Set the message's level outright.
     */
    public function level(string $level): static
    {
        $this->level = $level;

        return $this;
    }

    /**
     * Set the subject or heading.
     */
    public function subject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Set the opening line.
     */
    public function greeting(string $greeting): static
    {
        $this->greeting = $greeting;

        return $this;
    }

    /**
     * Set the closing line.
     */
    public function salutation(string $salutation): static
    {
        $this->salutation = $salutation;

        return $this;
    }

    /**
     * Add a line, before the action or after it depending on where we are.
     *
     * @param string|array<int, string> $line
     */
    public function line(string|array $line): static
    {
        return $this->with($line);
    }

    /**
     * Add several lines.
     *
     * @param iterable<int, string> $lines
     */
    public function lines(iterable $lines): static
    {
        foreach ($lines as $line) {
            $this->line($line);
        }

        return $this;
    }

    /**
     * Add a line, before the action or after it depending on where we are.
     *
     * @param string|array<int, string> $line
     */
    public function with(string|array $line): static
    {
        foreach ((array) $line as $text) {
            if ($this->action === null) {
                $this->introLines[] = $this->formatLine($text);
            } else {
                $this->outroLines[] = $this->formatLine($text);
            }
        }

        return $this;
    }

    /**
     * Set the call to action.
     */
    public function action(string $text, string $url): static
    {
        $this->action = new Action($text, $url);

        return $this;
    }

    /**
     * Collapse whitespace so a line written across several source lines reads
     * as one sentence.
     */
    protected function formatLine(string $line): string
    {
        return trim(preg_replace('/\s+/', ' ', $line));
    }

    /**
     * Get the message as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'level'      => $this->level,
            'subject'    => $this->subject,
            'greeting'   => $this->greeting,
            'salutation' => $this->salutation,
            'introLines' => $this->introLines,
            'outroLines' => $this->outroLines,
            'actionText' => $this->action?->text,
            'actionUrl'  => $this->action?->url,
        ];
    }
}
