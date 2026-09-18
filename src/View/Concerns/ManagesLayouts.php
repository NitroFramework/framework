<?php

namespace Nitro\View\Concerns;

use InvalidArgumentException;
use Nitro\View\Support\DebugRenderPipeline;

/**
 * View engine concern: layout inheritance and section resolution.
 *
 * Sections, the in-progress stack and the `@extends` parent live on the render
 * context, so they reset with every top-level render rather than accumulating
 * on the engine.
 */
trait ManagesLayouts
{
    /**
     * Placeholder token standing in for each section's parent content.
     *
     * @var array<string, string>
     */
    protected static array $parentPlaceholders = [];

    /** Per-process salt making the placeholder tokens unguessable. */
    protected static ?string $parentPlaceholderSalt = null;

    /**
     * Start a section, or set it outright in the inline form.
     */
    public function startSection(string $section, ?string $content = null): void
    {
        if (DebugRenderPipeline::isEnabled()) {
            DebugRenderPipeline::note("startSection({$section})");
        }

        if ($content === null) {
            if (ob_start()) {
                $this->context->sectionStack[] = $section;
            }
        } else {
            $this->extendSection($section, $content);
        }
    }

    /**
     * Stop the open section, extending what is already there unless told to
     * overwrite it.
     *
     * @return string The section that was closed.
     * @throws InvalidArgumentException When no section is open.
     */
    public function stopSection(bool $overwrite = false): string
    {
        if (empty($this->context->sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($this->context->sectionStack);

        if ($overwrite) {
            $this->context->sections[$last] = ob_get_clean();
        } else {
            $this->extendSection($last, ob_get_clean());
        }

        return $last;
    }

    /**
     * Close the open section; what compiled `@endsection` calls.
     */
    public function endSection(bool $overwrite = false): void
    {
        if (DebugRenderPipeline::isEnabled()) {
            DebugRenderPipeline::note(
                "endSection() stack=" . json_encode($this->context->sectionStack)
            );
        }
        $this->stopSection($overwrite);
    }

    /**
     * Close the open section and return its content; what `@show` calls.
     */
    public function yieldSection(): string
    {
        if (empty($this->context->sectionStack)) {
            return '';
        }

        return $this->yieldContent($this->stopSection());
    }

    /**
     * Close the open section and append it to what is already there.
     *
     * The parent placeholder goes in front of the appended content, so the
     * parent's own default lands before it when the section is extended.
     *
     * @throws InvalidArgumentException When no section is open.
     */
    public function appendSection(): void
    {
        if (empty($this->context->sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($this->context->sectionStack);
        $content = ob_get_clean();

        $this->context->sections[$last] = ($this->context->sections[$last] ?? '')
            . static::parentPlaceholder($last)
            . $content;
    }

    /**
     * Merge a section's content into what a child already defined.
     *
     * The child is held first, so the incoming parent content is substituted
     * into the child's `@parent` placeholder rather than replacing it.
     */
    protected function extendSection(string $section, string $content): void
    {
        if (isset($this->context->sections[$section])) {
            $content = str_replace(
                static::parentPlaceholder($section),
                $content,
                $this->context->sections[$section]
            );
        }

        $this->context->sections[$section] = $content;
    }

    /**
     * Get a section's content, dropping any placeholder never resolved.
     */
    public function yieldContent(string $section, string $default = ''): string
    {
        $content = $this->context->sections[$section] ?? $default;

        $content = str_replace(
            '--parent--holder--',
            '',
            str_replace(static::parentPlaceholder($section), '', $content)
        );

        return $content;
    }

    /** Alias of {@see yieldContent()}; what compiled `@yield` calls. */
    public function getSection(string $name, string $default = ''): string
    {
        return $this->yieldContent($name, $default);
    }

    /**
     * Get the placeholder token standing in for a section's parent content.
     *
     * Salted per process, so a template cannot emit a placeholder of its own
     * and have the engine substitute content into it.
     */
    public static function parentPlaceholder(string $section = ''): string
    {
        if (!isset(static::$parentPlaceholders[$section])) {
            static::$parentPlaceholders[$section] =
                '##parent-placeholder-' . sha1(static::parentPlaceholderSalt() . $section) . '##';
        }

        return static::$parentPlaceholders[$section];
    }

    /**
     * Get the per-process salt, minting it on first use.
     */
    protected static function parentPlaceholderSalt(): string
    {
        if (static::$parentPlaceholderSalt === null) {
            static::$parentPlaceholderSalt = bin2hex(random_bytes(8));
        }

        return static::$parentPlaceholderSalt;
    }

    /**
     * Get the placeholder for the open section; what compiled `@parent` emits.
     */
    public function getParentContent(): string
    {
        if (!empty($this->context->sectionStack)) {
            $last = end($this->context->sectionStack);
            return static::parentPlaceholder($last);
        }

        return '';
    }

    /**
     * Record the layout this view extends; what compiled `@extends` calls.
     */
    public function setParentView(string $parentView): void
    {
        $this->context->parentView = $parentView;
    }

    /**
     * Get the layout this view extends, if any.
     */
    public function getParentView(): ?string
    {
        return $this->context->parentView;
    }

    /**
     * Forget the layout this view extends.
     */
    public function clearParentView(): void
    {
        $this->context->parentView = null;
    }

    /**
     * Determine whether a section has content.
     */
    public function hasSection(string $name): bool
    {
        return isset($this->context->sections[$name]);
    }

    /**
     * Get every section's content.
     *
     * @return array<string, string>
     */
    public function getAllSections(): array
    {
        return $this->context->sections;
    }

    /**
     * Set a section's content, overriding whatever the template yields.
     */
    public function forceSection(string $name, string $content): void
    {
        $this->context->sections[$name] = $content;
    }

    /**
     * Discard all layout state between root renders.
     */
    public function flushSections(): void
    {
        $this->context->sections = [];
        $this->context->sectionStack = [];
        $this->context->parentView = null;
    }

    /**
     * Determine whether a section or stack is currently capturing output.
     */
    public function isCapturing(): bool
    {
        return !empty($this->context->sectionStack) || !empty($this->context->pushStack);
    }
}
