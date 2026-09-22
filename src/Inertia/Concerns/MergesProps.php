<?php

namespace Nitro\Inertia\Concerns;

/**
 * The merge flags a prop carries into the page payload.
 *
 * Nothing here merges anything: the client does that. This records the intent
 * so the response can tell it which props to combine and what identifies a
 * row, which is why the flags are reported in the payload's metadata rather
 * than applied to the value.
 */
trait MergesProps
{
    protected bool $merge = false;

    protected bool $deepMerge = false;

    /** @var array<int, string> */
    protected array $matchOn = [];

    /**
     * Whether new values join at the end of the existing list.
     *
     * Scrolling down extends the end; scrolling up extends the beginning, and
     * the same endpoint answers both — so which one applies is decided per
     * request rather than per prop.
     */
    protected bool $append = true;

    /** @var array<int, string> */
    protected array $appendsAtPaths = [];

    /** @var array<int, string> */
    protected array $prependsAtPaths = [];

    /**
     * Append new values, optionally only within a nested path.
     *
     * A paginator sends its rows under 'data' and its counts beside them, so
     * the merge has to apply to that one key — appending the whole prop would
     * duplicate the page metadata as well.
     *
     * @return static
     */
    public function append(?string $path = null)
    {
        $this->append = true;

        if ($path !== null && ! in_array($path, $this->appendsAtPaths, true)) {
            $this->appendsAtPaths[] = $path;
        }

        return $this->merge();
    }

    /** @return static */
    public function prepend(?string $path = null)
    {
        $this->append = false;

        if ($path !== null && ! in_array($path, $this->prependsAtPaths, true)) {
            $this->prependsAtPaths[] = $path;
        }

        return $this->merge();
    }

    public function appendsAtRoot(): bool
    {
        return $this->append && $this->appendsAtPaths === [];
    }

    public function prependsAtRoot(): bool
    {
        return ! $this->append && $this->prependsAtPaths === [];
    }

    /** @return array<int, string> */
    public function appendsAtPaths(): array
    {
        return $this->appendsAtPaths;
    }

    /** @return array<int, string> */
    public function prependsAtPaths(): array
    {
        return $this->prependsAtPaths;
    }

    /** @return static */
    public function merge()
    {
        $this->merge = true;

        return $this;
    }

    /**
     * Merge recursively rather than at the top level.
     *
     * Implies merge(): asking for a deep merge without asking for a merge is
     * not a state worth having.
     *
     * @return static
     */
    public function deepMerge()
    {
        $this->deepMerge = true;

        return $this->merge();
    }

    /**
     * The keys identifying a row, so a re-sent item replaces its earlier copy.
     *
     * @param  string|array<int, string> $matchOn
     * @return static
     */
    public function matchOn(string|array $matchOn)
    {
        $this->matchOn = is_array($matchOn) ? $matchOn : [$matchOn];

        return $this;
    }

    public function shouldMerge(): bool
    {
        return $this->merge;
    }

    public function shouldDeepMerge(): bool
    {
        return $this->deepMerge;
    }

    /** @return array<int, string> */
    public function matchesOn(): array
    {
        return $this->matchOn;
    }
}
