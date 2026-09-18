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
}
