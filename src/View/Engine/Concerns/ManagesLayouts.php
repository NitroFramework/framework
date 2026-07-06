<?php

namespace Nitro\View\Engine\Concerns;

use InvalidArgumentException;
use Nitro\View\Support\DebugRenderPipeline;

/**
 * View engine concern: layout inheritance and section resolution.
 */
trait ManagesLayouts
{
    /**
     * Section/layout state (sections, in-progress stack, @extends parent) now
     * lives on {@see \Nitro\View\Engine\RenderContext} via $this->context, so
     * it resets per top-level render instead of accumulating on the renderer.
     */

    /** @var array<string, string> Parent placeholder tokens per section */
    protected static array $parentPlaceholders = [];

    /** @var string|null Random salt per request */
    protected static ?string $parentPlaceholderSalt = null;

    /**
     * Start a section. Supports inline form: @section('title', 'My Page')
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
     * Stop the current section.
     * Default behavior is EXTEND (like Laravel), not overwrite.
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
     * Alias — compiled @endsection calls this.
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
     * Stop section and immediately echo its content (@show).
     */
    public function yieldSection(): string
    {
        if (empty($this->context->sectionStack)) {
            return '';
        }

        return $this->yieldContent($this->stopSection());
    }

    /**
     * Stop section and append to existing content (@append).
     */
    public function appendSection(): void
    {
        if (empty($this->context->sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($this->context->sectionStack);
        $content = ob_get_clean();

        // Prepend the parent placeholder so the parent's default content
        // gets inserted before the appended content during extendSection
        $this->context->sections[$last] = ($this->context->sections[$last] ?? '')
            . static::parentPlaceholder($last)
            . $content;
    }

    /**
     * Extend (merge) a section — handles @parent placeholder replacement.
     * This is the core of Laravel's section inheritance.
     */
    protected function extendSection(string $section, string $content): void
    {
        if (isset($this->context->sections[$section])) {
            // Child content is in $this->context->sections[$section]
            // $content is the new (parent) content
            // Replace @parent placeholders in child's content with the parent content
            $content = str_replace(
                static::parentPlaceholder($section),
                $content,
                $this->context->sections[$section]
            );
        }

        $this->context->sections[$section] = $content;
    }

    /**
     * Get section content for @yield, replacing any remaining @parent placeholders.
     */
    public function yieldContent(string $section, string $default = ''): string
    {
        $content = $this->context->sections[$section] ?? $default;

        // Strip any unresolved @parent placeholders
        $content = str_replace(
            '--parent--holder--',
            '',
            str_replace(static::parentPlaceholder($section), '', $content)
        );

        return $content;
    }

    // Keep getSection as alias for compiled @yield
    public function getSection(string $name, string $default = ''): string
    {
        return $this->yieldContent($name, $default);
    }

    /**
     * Generate a unique parent placeholder token for a section.
     * Randomized per-request so users can't inject fake placeholders.
     */
    public static function parentPlaceholder(string $section = ''): string
    {
        if (!isset(static::$parentPlaceholders[$section])) {
            static::$parentPlaceholders[$section] =
                '##parent-placeholder-' . sha1(static::parentPlaceholderSalt() . $section) . '##';
        }

        return static::$parentPlaceholders[$section];
    }

    protected static function parentPlaceholderSalt(): string
    {
        if (static::$parentPlaceholderSalt === null) {
            static::$parentPlaceholderSalt = bin2hex(random_bytes(8));
        }

        return static::$parentPlaceholderSalt;
    }

    /**
     * Get the parent content placeholder — compiled @parent outputs this.
     */
    public function getParentContent(): string
    {
        if (!empty($this->context->sectionStack)) {
            $last = end($this->context->sectionStack);
            return static::parentPlaceholder($last);
        }

        return '';
    }

    /** @extends */
    public function setParentView(string $parentView): void
    {
        $this->context->parentView = $parentView;
    }

    public function getParentView(): ?string
    {
        return $this->context->parentView;
    }

    public function clearParentView(): void
    {
        $this->context->parentView = null;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->context->sections[$name]);
    }

    public function getAllSections(): array
    {
        return $this->context->sections;
    }

    public function forceSection(string $name, string $content): void
    {
        $this->context->sections[$name] = $content;
    }

    /**
     * Reset all layout state between root renders.
     */
    public function flushSections(): void
    {
        $this->context->sections = [];
        $this->context->sectionStack = [];
        $this->context->parentView = null;
    }

    public function isCapturing(): bool
    {
        return !empty($this->context->sectionStack) || !empty($this->context->pushStack);
    }
}
