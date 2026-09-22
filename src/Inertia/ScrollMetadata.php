<?php

namespace Nitro\Inertia;

use InvalidArgumentException;
use Nitro\Database\Query\Paginator;
use Nitro\Inertia\Contracts\ProvidesScrollMetadata;

/**
 * Scroll position, read from a paginator or stated outright.
 */
class ScrollMetadata implements ProvidesScrollMetadata
{
    public function __construct(
        private string $pageName,
        private int|string|null $previousPage = null,
        private int|string|null $nextPage = null,
        private int|string|null $currentPage = null,
    ) {
    }

    /**
     * Read the position from a paginator.
     *
     * Both ends are null-when-absent rather than clamped: the client uses
     * their presence to decide whether there is anything left to load, so a
     * last page reporting a next page of itself would scroll forever.
     */
    public static function fromPaginator(mixed $value): self
    {
        if (! $value instanceof Paginator) {
            throw new InvalidArgumentException(
                'Scroll metadata can be read from a Paginator; for anything else, pass a callback that returns it.'
            );
        }

        $current = $value->currentPage();

        return new self(
            $value->getPageName(),
            $current > 1 ? $current - 1 : null,
            $value->hasMorePages() ? $current + 1 : null,
            $current,
        );
    }

    public function getPageName(): string
    {
        return $this->pageName;
    }

    public function getPreviousPage(): int|string|null
    {
        return $this->previousPage;
    }

    public function getNextPage(): int|string|null
    {
        return $this->nextPage;
    }

    public function getCurrentPage(): int|string|null
    {
        return $this->currentPage;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'pageName'     => $this->getPageName(),
            'previousPage' => $this->getPreviousPage(),
            'nextPage'     => $this->getNextPage(),
            'currentPage'  => $this->getCurrentPage(),
        ];
    }
}
