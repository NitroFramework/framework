<?php

namespace Nitro\Database\Factory;

/**
 * Wraps a Generator and remembers every value it returns, retrying when
 * the underlying call produces a duplicate. Throws after $maxRetries to
 * avoid infinite loops when the pool is exhausted (e.g. asking for 1000
 * unique values from a 20-item set).
 *
 *   $faker->unique()->email();         // never collides within this call site
 *   $faker->unique()->randomElement($pool);  // each call returns a fresh item
 *
 * The "uniqueness scope" is the UniqueGenerator instance itself. Each
 * call to $faker->unique() produces a NEW instance — so two unrelated
 * factories don't share a memory of seen values.
 */
class UniqueGenerator
{
    private array $seen = [];
    private int $maxRetries = 1000;

    public function __construct(private Generator $generator) {}

    public function __call(string $method, array $args): mixed
    {
        $key = $method . ':' . md5(serialize($args));
        $this->seen[$key] ??= [];

        for ($i = 0; $i < $this->maxRetries; $i++) {
            $value = $this->generator->{$method}(...$args);
            $hash  = is_scalar($value) ? (string) $value : md5(serialize($value));

            if (!in_array($hash, $this->seen[$key], true)) {
                $this->seen[$key][] = $hash;
                return $value;
            }
        }

        throw new \RuntimeException(
            "UniqueGenerator could not produce a unique value for {$method}() "
            . "after {$this->maxRetries} attempts. The underlying pool is likely exhausted."
        );
    }
}
