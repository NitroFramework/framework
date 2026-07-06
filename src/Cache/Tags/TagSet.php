<?php

namespace Nitro\Cache\Tags;

use Nitro\Cache\Contracts\StoreInterface;

/**
 * A named set of cache tags used to namespace and flush tagged entries together.
 */
class TagSet
{
    /**
     * The cache store instance.
     *
     * @var StoreInterface
     */
    protected StoreInterface $store;

    /**
     * The tag names.
     *
     * @var array
     */
    protected array $names = [];

    /**
     * @param StoreInterface $store
     * @param array          $names
     */
    public function __construct(StoreInterface $store, array $names = [])
    {
        $this->store = $store;
        $this->names = $names;
    }

    /**
     * Reset all tags in the set (effectively invalidating all tagged cache items).
     *
     * @return void
     */
    public function reset(): void
    {
        foreach ($this->names as $name) {
            $this->resetTag($name);
        }
    }

    /**
     * Reset a single tag by generating a new unique version ID.
     *
     * @param string $name
     * @return string The new tag ID
     */
    public function resetTag(string $name): string
    {
        $id = $this->generateUniqueId();

        $this->store->forever($this->tagKey($name), $id);

        return $id;
    }

    /**
     * Get the unique namespace for this tag set.
     * Combines all tag version IDs into a single string.
     *
     * @return string
     */
    public function getNamespace(): string
    {
        $ids = [];

        foreach ($this->names as $name) {
            $ids[] = $this->tagId($name);
        }

        return implode('|', $ids);
    }

    /**
     * Get the tag ID for a given tag name.
     * Creates a new one if the tag doesn't exist yet.
     *
     * @param string $name
     * @return string
     */
    protected function tagId(string $name): string
    {
        $id = $this->store->get($this->tagKey($name));

        if ($id === null) {
            return $this->resetTag($name);
        }

        return (string) $id;
    }

    /**
     * Get the storage key for a tag's version ID.
     *
     * @param string $name
     * @return string
     */
    public function tagKey(string $name): string
    {
        return 'tag:' . $name . ':key';
    }

    /**
     * Get the tag names in this set.
     *
     * @return array
     */
    public function getNames(): array
    {
        return $this->names;
    }

    /**
     * Generate a unique ID for tag versioning.
     *
     * @return string
     */
    protected function generateUniqueId(): string
    {
        return str_replace('.', '', uniqid('', true));
    }
}
