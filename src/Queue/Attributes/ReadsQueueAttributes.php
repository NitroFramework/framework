<?php

namespace Nitro\Queue\Attributes;

use ReflectionClass;
use Throwable;

/**
 * Reads a job's terms from an attribute when no property sets them.
 *
 * A property and an attribute say the same thing two ways, so one has
 * to win. In order:
 *
 *   1. A value assigned at run time — the property no longer holds the
 *      value it was declared with. That is a decision made about this
 *      job on this dispatch, and nothing at the class level undoes it.
 *   2. The attribute. It and a declared default are both the author
 *      writing the same class, and the attribute is the more
 *      deliberate — a default is often the one inherited from the base
 *      job and left alone.
 *   3. The property's declared default.
 */
trait ReadsQueueAttributes
{
    /**
     * What reflection found, per class and knob.
     *
     * Static rather than per-instance for two reasons: a job is
     * serialized into its payload, and an instance property would ride
     * along and arrive stale; and a class's attributes and declared
     * defaults are the same for every instance, so looking them up
     * once per dispatch would be reflection for no new answer.
     *
     * @var array<string, array{default: mixed, attribute: mixed, exists: bool}>
     */
    private static array $queueAttributeCache = [];

    /**
     * The value for one knob.
     *
     * @param class-string $attribute The attribute that may carry it.
     * @param string       $property  The property that may carry it.
     */
    protected function queueAttribute(string $attribute, string $property, mixed $default = null): mixed
    {
        $resolved = static::$queueAttributeCache[static::class . '::' . $property]
            ??= $this->inspectQueueAttribute($attribute, $property);

        $current = $resolved['exists'] ? $this->{$property} : null;

        // A property that no longer holds the value it was declared with
        // was set on purpose, and nothing written above the class should
        // quietly override what a subclass went out of its way to say.
        if ($resolved['exists'] && $current !== $resolved['default']) {
            return $current;
        }

        return $resolved['attribute'] ?? $current ?? $default;
    }

    /**
     * What the class itself says, before any one instance is consulted.
     *
     * @return array{default: mixed, attribute: mixed, exists: bool}
     */
    private function inspectQueueAttribute(string $attribute, string $property): array
    {
        $reflection = new ReflectionClass(static::class);
        $instance = $this->queueAttributeInstance($reflection, $attribute);

        $value = null;

        if ($instance !== null) {
            $values = get_object_vars($instance);

            // An attribute with no value at all — FailOnTimeout — says
            // its one thing by being there.
            $value = $values === [] ? true : reset($values);
        }

        return [
            'default' => $reflection->getDefaultProperties()[$property] ?? null,
            'attribute' => $value,
            'exists' => $reflection->hasProperty($property),
        ];
    }

    /** Look for the attribute on this class, its traits, then its parents. */
    private function queueAttributeInstance(ReflectionClass $reflection, string $attribute): ?object
    {
        try {
            do {
                $attributes = $reflection->getAttributes($attribute);

                if ($attributes !== []) {
                    return $attributes[0]->newInstance();
                }

                foreach ($reflection->getTraits() as $trait) {
                    $attributes = $trait->getAttributes($attribute);

                    if ($attributes !== []) {
                        return $attributes[0]->newInstance();
                    }
                }
            } while ($reflection = $reflection->getParentClass());
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
