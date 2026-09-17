<?php

namespace Nitro\Database\Query;

use ArrayIterator;
use IteratorAggregate;
use Countable;
use Nitro\View\Support\HtmlString;

/**
 * A paginated result set — items plus page/total metadata.
 */
class Paginator implements IteratorAggregate, Countable
{
    /**
     * How the current page number is resolved from the request. Registered by
     * the HTTP layer (a service provider) so the database layer never reads
     * $_GET directly — keeping it HTTP-free and testable. Mirrors Laravel's
     * Paginator::currentPageResolver.
     *
     * @var (\Closure(string): mixed)|null
     */
    protected static ?\Closure $currentPageResolver = null;

    public function __construct(
        protected array $items,
        protected int $total,
        protected int $perPage,
        protected int $currentPage
    ) {}

    /** Register the resolver that reads the current page from the request. */
    public static function currentPageResolverUsing(?\Closure $resolver): void
    {
        static::$currentPageResolver = $resolver;
    }

    /**
     * Resolve the current page via the registered resolver, falling back to
     * $default when no resolver is set (e.g. console) or the value is invalid.
     */
    public static function resolveCurrentPage(string $pageName = 'page', int $default = 1): int
    {
        if (static::$currentPageResolver !== null) {
            $resolved = (static::$currentPageResolver)($pageName);
            if ((is_int($resolved) || (is_string($resolved) && ctype_digit($resolved))) && (int) $resolved >= 1) {
                return (int) $resolved;
            }
        }

        return $default;
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function from(): ?int
    {
        return $this->total > 0 ? (($this->currentPage - 1) * $this->perPage) + 1 : null;
    }

    public function to(): ?int
    {
        if ($this->total === 0) return null;
        return min($this->currentPage * $this->perPage, $this->total);
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    public function toArray(): array
    {
        return [
            'data' => $this->items,
            'current_page' => $this->currentPage,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'last_page' => $this->lastPage(),
            'from' => $this->from(),
            'to' => $this->to(),
            'has_more_pages' => $this->hasMorePages(),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    public function total(): int
    {
        return $this->total;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function items(): array
    {
        return $this->items;
    }

    public function firstItem(): ?int
    {
        return $this->total > 0 ? $this->from() : null;
    }

    public function lastItem(): ?int
    {
        return $this->total > 0 ? $this->to() : null;
    }

    public function hasPages(): bool
    {
        return $this->total > $this->perPage;
    }

    // ─── Links ────────────────────────────────────────────

    /**
     * How the current URL is resolved from the request. Registered by the HTTP
     * layer for the same reason as {@see $currentPageResolver}: the query layer
     * must not read superglobals.
     *
     * @var (\Closure(): array{path: string, query: array<string, mixed>})|null
     */
    protected static ?\Closure $currentPathResolver = null;

    /** Extra query parameters to keep on generated links. */
    protected array $appends = [];

    protected string $pageName = 'page';

    public static function currentPathResolverUsing(?\Closure $resolver): void
    {
        static::$currentPathResolver = $resolver;
    }

    /** @return array{path: string, query: array<string, mixed>} */
    protected static function resolveCurrentPath(): array
    {
        if (static::$currentPathResolver !== null) {
            $resolved = (static::$currentPathResolver)();

            if (is_array($resolved) && isset($resolved['path'])) {
                return [
                    'path'  => (string) $resolved['path'],
                    'query' => is_array($resolved['query'] ?? null) ? $resolved['query'] : [],
                ];
            }
        }

        return ['path' => '/', 'query' => []];
    }

    public function setPageName(string $name): static
    {
        $this->pageName = $name;
        return $this;
    }

    /**
     * Keep additional query parameters on every generated link.
     *
     * @param array<string, mixed> $parameters
     */
    public function appends(array $parameters): static
    {
        unset($parameters[$this->pageName]);
        $this->appends = array_merge($this->appends, $parameters);
        return $this;
    }

    /** Keep the request's existing query string on every generated link. */
    public function withQueryString(): static
    {
        return $this->appends(static::resolveCurrentPath()['query']);
    }

    public function url(int $page): string
    {
        $page = max($page, 1);
        $current = static::resolveCurrentPath();

        $query = array_merge($current['query'], $this->appends, [$this->pageName => $page]);

        return $current['path'] . '?' . http_build_query($query);
    }

    public function previousPageUrl(): ?string
    {
        return $this->currentPage > 1 ? $this->url($this->currentPage - 1) : null;
    }

    public function nextPageUrl(): ?string
    {
        return $this->hasMorePages() ? $this->url($this->currentPage + 1) : null;
    }

    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    public function onLastPage(): bool
    {
        return ! $this->hasMorePages();
    }

    /**
     * Page numbers to render, with null marking an elided run.
     *
     * Shows the first and last page, plus $each either side of the current one,
     * so the control stays a fixed width however many pages there are.
     *
     * @return array<int, int|null>
     */
    public function elidedPageRange(int $each = 2): array
    {
        $last = $this->lastPage();

        if ($last <= ($each * 2) + 5) {
            return range(1, $last);
        }

        $window = range(
            max(2, $this->currentPage - $each),
            min($last - 1, $this->currentPage + $each)
        );

        $pages = [1];

        if ($window[0] > 2) {
            $pages[] = null;
        }

        foreach ($window as $page) {
            $pages[] = $page;
        }

        if (end($window) < $last - 1) {
            $pages[] = null;
        }

        $pages[] = $last;

        return $pages;
    }

    /**
     * Rendered pagination control. Returns an Htmlable so `{{ $p->links() }}`
     * emits markup rather than an escaped string.
     */
    public function links(): HtmlString
    {
        if (! $this->hasPages()) {
            return new HtmlString('');
        }

        $html = '<nav class="pagination" role="navigation" aria-label="Pagination">';

        $html .= $this->onFirstPage()
            ? '<span class="pagination-prev disabled" aria-disabled="true">&laquo;</span>'
            : '<a class="pagination-prev" rel="prev" href="' . e($this->previousPageUrl()) . '">&laquo;</a>';

        foreach ($this->elidedPageRange() as $page) {
            if ($page === null) {
                $html .= '<span class="pagination-gap">&hellip;</span>';
                continue;
            }

            $html .= $page === $this->currentPage
                ? '<span class="pagination-page current" aria-current="page">' . $page . '</span>'
                : '<a class="pagination-page" href="' . e($this->url($page)) . '">' . $page . '</a>';
        }

        $html .= $this->onLastPage()
            ? '<span class="pagination-next disabled" aria-disabled="true">&raquo;</span>'
            : '<a class="pagination-next" rel="next" href="' . e($this->nextPageUrl()) . '">&raquo;</a>';

        return new HtmlString($html . '</nav>');
    }

    /** Alias kept for callers that render the control explicitly. */
    public function render(): HtmlString
    {
        return $this->links();
    }
}
