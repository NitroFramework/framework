<?php

namespace Nitro\Livewire\Concerns;

use Nitro\Livewire\Attributes\Url;

/**
 * Adds paginator navigation to a component. The current page lives in a
 * URL-bound $page property (so pagination is shareable and survives a refresh);
 * the gotoPage/nextPage/previousPage actions are what the pagination view's
 * wire:click links call. Pass $this->page to the query builder's paginate():
 *
 *     Student::query()->orderByDesc('id')->paginate(10, $this->page)
 */
trait WithPagination
{
    /** The active page, kept in the query string as ?page=. */
    #[Url(as: 'page')]
    public int $page = 1;

    /** Jump to an explicit page (clamped to a sane lower bound). */
    public function gotoPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /** Advance one page. */
    public function nextPage(): void
    {
        $this->page++;
    }

    /** Step back one page (never below the first). */
    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    /** Return to the first page — call after a filter/search changes. */
    public function resetPage(): void
    {
        $this->page = 1;
    }
}
