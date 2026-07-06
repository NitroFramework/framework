<?php

namespace Nitro\Database\Query;

use ArrayIterator;
use IteratorAggregate;
use Countable;

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
}
