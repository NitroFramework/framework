<?php

namespace Nitro\Mail;

use Nitro\View\Markdown\Markdown as MarkdownParser;

/**
 * Renders a markdown mail view into an HTML and a plain-text part.
 *
 * Writing an email twice — once in HTML that has to survive a decade
 * of mail clients, once in text — is how the two drift apart. A
 * markdown view is written once and becomes both.
 */
class Markdown
{
    /** Where the layout and components are found. */
    public const NAMESPACE = 'nitro-mail';

    /** @param array<int, string> $componentPaths Namespaces searched first. */
    public function __construct(
        protected string $theme = 'default',
        protected array $componentPaths = [],
    ) {}

    /**
     * The HTML part: the view's markdown, converted and laid out.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $view, array $data = []): string
    {
        $body = MarkdownParser::render($this->renderView($view, $data));

        return $this->renderView(static::NAMESPACE . '::layout', [
            'slot' => $body,
            'theme' => $this->themeCss(),
        ]);
    }

    /**
     * The text part: the view's markdown as it was written.
     *
     * Markdown is already the plain-text form of what it describes, so
     * converting and stripping would only lose the formatting a reader
     * without HTML is left with.
     *
     * @param array<string, mixed> $data
     */
    public function renderText(string $view, array $data = []): string
    {
        return trim($this->renderView($view, $data));
    }

    /** Use a different theme for the inline styles. */
    public function theme(string $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    /**
     * The stylesheet the layout inlines.
     *
     * Read from the application's own themes when it has one by that
     * name, so a project can restyle its mail without republishing the
     * layout.
     */
    protected function themeCss(): string
    {
        foreach ($this->themePaths() as $path) {
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        return '';
    }

    /** @return array<int, string> */
    protected function themePaths(): array
    {
        $paths = [];

        if (function_exists('resource_path')) {
            $paths[] = resource_path('views/vendor/mail/themes/' . $this->theme . '.css');
        }

        $paths[] = __DIR__ . '/views/themes/' . $this->theme . '.css';
        $paths[] = __DIR__ . '/views/themes/default.css';

        return $paths;
    }

    /** @param array<string, mixed> $data */
    protected function renderView(string $view, array $data): string
    {
        return \app('view')->render($view, $data);
    }
}
