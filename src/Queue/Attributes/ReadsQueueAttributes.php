<?php

namespace Nitro\Queue\Attributes;

use ReflectionClass;
use Throwable;

/**
 * Read a job's terms from an attribute when no property sets them.
 *
 * A value assigned at run time wins, then the attribute, then the
 * property's declared default.
 */
trait ReadsQueueAttributes
{
    /**
     * What reflection found, per class and term.
     *
     * Static because an instance property would be serialized into the
     * job's payload and arrive stale.
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

        // A property no longer holding its declared value was set on
        // purpose, so nothing above the class overrides it.
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

            // An attribute with no value says its one thing by being there.
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
