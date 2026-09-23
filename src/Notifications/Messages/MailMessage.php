<?php

namespace Nitro\Notifications\Messages;

/**
 * A notification's email, described in lines rather than markup.
 *
 * The channel turns this into a {@see \Nitro\Mail\Message}, so a notification
 * says what it means and the rendering stays in one place. Naming a view with
 * {@see view()} takes over completely — the lines are then that view's data.
 */
class MailMessage extends SimpleMessage
{
    /** @var array<int, string> */
    public array $from = [];

    /** @var array<int, string> */
    public array $to = [];

    /** @var array<int, string> */
    public array $cc = [];

    /** @var array<int, string> */
    public array $bcc = [];

    /** @var array<int, string> */
    public array $replyTo = [];

    /** View rendering the message, or null to use the built-in layout. */
    public ?string $view = null;

    /** @var array<string, mixed> Extra data for the view. */
    public array $viewData = [];

    /** @var array<int, array{path: string, options: array<string, mixed>}> */
    public array $attachments = [];

    /** @var array<int, array{data: string, name: string, options: array<string, mixed>}> */
    public array $rawAttachments = [];

    /** @var array<string, string> */
    public array $headers = [];

    /** A markdown view, rendered to both an HTML and a text part. */
    public ?string $markdown = null;

    /** A plain-text view, sent alongside the HTML one. */
    public ?string $textView = null;

    /** The theme the markdown styles come from. */
    public ?string $theme = null;

    /** @var array<int, array{disk: ?string, path: string, name: ?string, options: array<string, mixed>}> */
    public array $storageAttachments = [];

    /** @var array<int, string> */
    public array $tags = [];

    /** @var array<string, string|int> */
    public array $metadata = [];

    /** 1 is highest, 5 lowest; null leaves the header off. */
    public ?int $priority = null;

    /**
     * Set the sender.
     */
    public function from(string $address, ?string $name = null): static
    {
        $this->from = [$address, $name];

        return $this;
    }

    /**
     * Add a recipient.
     *
     * @param string|array<int, string> $address
     */
    public function to(string|array $address): static
    {
        $this->to = array_merge($this->to, (array) $address);

        return $this;
    }

    /**
     * Add a carbon-copy recipient.
     *
     * @param string|array<int, string> $address
     */
    public function cc(string|array $address): static
    {
        $this->cc = array_merge($this->cc, (array) $address);

        return $this;
    }

    /**
     * Add a blind carbon-copy recipient.
     *
     * @param string|array<int, string> $address
     */
    public function bcc(string|array $address): static
    {
        $this->bcc = array_merge($this->bcc, (array) $address);

        return $this;
    }

    /**
     * Set the reply-to address.
     *
     * @param string|array<int, string> $address
     */
    public function replyTo(string|array $address): static
    {
        $this->replyTo = array_merge($this->replyTo, (array) $address);

        return $this;
    }

    /**
     * Render the message with a view of your own instead of the built-in one.
     *
     * @param array<string, mixed> $data
     */
    public function view(string $view, array $data = []): static
    {
        $this->view = $view;
        $this->viewData = $data;

        return $this;
    }

    /**
     * Attach a file.
     *
     * @param array<string, mixed> $options
     */
    public function attach(string $path, array $options = []): static
    {
        $this->attachments[] = ['path' => $path, 'options' => $options];

        return $this;
    }

    /**
     * Attach in-memory data as a file.
     *
     * @param array<string, mixed> $options
     */
    public function attachData(string $data, string $name, array $options = []): static
    {
        $this->rawAttachments[] = ['data' => $data, 'name' => $name, 'options' => $options];

        return $this;
    }

    /**
     * Add a header to the outgoing message.
     */
    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Get the data the built-in layout renders from.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return array_merge($this->toArray(), $this->viewData);
    }

    /** Render a markdown view rather than the built-in layout. */
    public function markdown(string $view, array $data = []): static
    {
        $this->markdown = $view;
        $this->viewData = array_merge($this->viewData, $data);

        return $this;
    }

    /** A plain-text view, sent alongside the HTML one. */
    public function text(string $view, array $data = []): static
    {
        $this->textView = $view;
        $this->viewData = array_merge($this->viewData, $data);

        return $this;
    }

    /** Use a named theme for the markdown styles. */
    public function theme(string $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    /** Attach a file from the default storage disk. */
    public function attachFromStorage(string $path, ?string $name = null, array $options = []): static
    {
        $this->storageAttachments[] = ['disk' => null, 'path' => $path, 'name' => $name, 'options' => $options];

        return $this;
    }

    /** Attach a file from a named storage disk. */
    public function attachFromStorageDisk(string $disk, string $path, ?string $name = null, array $options = []): static
    {
        $this->storageAttachments[] = ['disk' => $disk, 'path' => $path, 'name' => $name, 'options' => $options];

        return $this;
    }

    /**
     * Attach several files at once.
     *
     * @param array<int|string, mixed> $files
     */
    public function attachMany(array $files): static
    {
        foreach ($files as $path => $options) {
            is_int($path) ? $this->attach($options) : $this->attach($path, (array) $options);
        }

        return $this;
    }

    /** Tag the message, for a provider that groups by one. */
    public function tag(string $tag): static
    {
        $this->tags[] = $tag;

        return $this;
    }

    /** Attach a value a provider echoes back on a webhook. */
    public function metadata(string $key, string|int $value): static
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /** Set the X-Priority header; 1 is highest, 5 lowest. */
    public function priority(int $level): static
    {
        $this->priority = max(1, min(5, $level));

        return $this;
    }
}
