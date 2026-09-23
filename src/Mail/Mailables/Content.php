<?php

namespace Nitro\Mail\Mailables;

/**
 * What is inside a message: the view that renders it and its data.
 *
 * A mailable declares one in content(). Naming both a view and a text
 * view sends a multipart message with both.
 */
class Content
{
    /**
     * @param ?string $view       The HTML view.
     * @param ?string $html       The same, under the name the docs use.
     * @param ?string $text       A plain-text view, sent alongside.
     * @param ?string $markdown   A markdown view, rendered to both parts.
     * @param array<string, mixed> $with Data for the view.
     * @param ?string $htmlString HTML given directly, rendering no view.
     */
    public function __construct(
        public ?string $view = null,
        ?string $html = null,
        public ?string $text = null,
        public ?string $markdown = null,
        public array $with = [],
        public ?string $htmlString = null,
    ) {
        $this->view ??= $html;
    }

    public function view(string $view): static
    {
        $this->view = $view;

        return $this;
    }

    public function html(string $view): static
    {
        return $this->view($view);
    }

    public function text(string $view): static
    {
        $this->text = $view;

        return $this;
    }

    public function markdown(string $view): static
    {
        $this->markdown = $view;

        return $this;
    }

    /** Give the body directly, rather than naming a view to render. */
    public function htmlString(string $html): static
    {
        $this->htmlString = $html;

        return $this;
    }

    /** @param string|array<string, mixed> $key */
    public function with(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->with = array_merge($this->with, $key);
        } else {
            $this->with[$key] = $value;
        }

        return $this;
    }
}
