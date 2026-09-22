<?php

namespace Nitro\Inertia\Props;

use Nitro\Http\Request;
use Nitro\Inertia\Concerns\DefersProps;
use Nitro\Inertia\Concerns\MergesProps;
use Nitro\Inertia\Concerns\ResolvesCallables;
use Nitro\Inertia\Contracts\Deferrable;
use Nitro\Inertia\Contracts\Mergeable;
use Nitro\Inertia\Contracts\ProvidesScrollMetadata;
use Nitro\Inertia\ScrollMetadata;
use Nitro\Inertia\Support\Header;

/**
 * A page of a sequence the client keeps extending.
 *
 * Merging is only half of it. The client also has to know where it is — what
 * to ask for next, what to ask for going back, and under which parameter —
 * so this carries that alongside the rows. Which end gets extended is decided
 * per request, because the same endpoint serves scrolling both ways.
 */
class ScrollProp implements Deferrable, Mergeable
{
    use DefersProps;
    use MergesProps;
    use ResolvesCallables;

    private mixed $resolved = null;

    private bool $hasResolved = false;

    /** @var ProvidesScrollMetadata|callable|null */
    private $metadata;

    public function __construct(
        private mixed $value,
        private string $wrapper = 'data',
        ProvidesScrollMetadata|callable|null $metadata = null,
    ) {
        $this->merge = true;
        $this->metadata = $metadata;
    }

    /**
     * Point the merge at whichever end the client is extending.
     *
     * Only the client knows: scrolling down wants the next page appended,
     * scrolling up wants the previous one prepended, and the request is
     * otherwise identical.
     */
    public function configureMergeIntent(Request $request): static
    {
        return $request->header(Header::INFINITE_SCROLL_MERGE_INTENT) === 'prepend'
            ? $this->prepend($this->wrapper)
            : $this->append($this->wrapper);
    }

    /**
     * The position, for the client to continue from.
     *
     * @return array{pageName: string, previousPage: int|string|null, nextPage: int|string|null, currentPage: int|string|null}
     */
    public function metadata(): array
    {
        $provider = $this->metadataProvider();

        return [
            'pageName'     => $provider->getPageName(),
            'previousPage' => $provider->getPreviousPage(),
            'nextPage'     => $provider->getNextPage(),
            'currentPage'  => $provider->getCurrentPage(),
        ];
    }

    private function metadataProvider(): ProvidesScrollMetadata
    {
        if ($this->metadata instanceof ProvidesScrollMetadata) {
            return $this->metadata;
        }

        $value = $this();

        return $this->metadata === null
            ? ScrollMetadata::fromPaginator($value)
            : ($this->metadata)($value);
    }

    /**
     * Resolved once and kept.
     *
     * The value is needed twice — for the payload and to read the position
     * from — and running a paginated query a second time would both cost
     * another round trip and risk reporting a position from different rows.
     */
    public function __invoke(): mixed
    {
        if (! $this->hasResolved) {
            $this->resolved = $this->resolveCallable($this->value);
            $this->hasResolved = true;
        }

        return $this->resolved;
    }
}
