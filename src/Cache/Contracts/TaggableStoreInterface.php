<?php

namespace Nitro\Cache\Contracts;

use Nitro\Cache\Tags\TaggedCache;

/**
 * Contract for a cache store that supports tag-scoped entries.
 */
interface TaggableStoreInterface extends StoreInterface
{
    /**
     * Begin executing a new tags operation.
     *
     * @param array|string $names
     * @return TaggedCache
     */
    public function tags(array|string $names): TaggedCache;
}
