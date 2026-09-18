<?php

namespace Nitro\Queue;

use Nitro\Database\Model\Model;
use Nitro\Support\Collection;
use ReflectionClass;
use ReflectionProperty;

/**
 * Stores models on a job by key and re-reads them when it runs.
 *
 *     class SendInvoice extends Job
 *     {
 *         use SerializesModels;
 *
 *         public function __construct(public Order $order) {}
 *     }
 *
 * Without this the whole model — every attribute and every loaded relation —
 * goes into the payload, and the job runs against whatever the row looked
 * like when it was dispatched. Storing the key instead keeps the payload
 * small and gives the job the row as it is when it runs.
 *
 * A model that has since been deleted comes back as null, so a job holding
 * one should say what it wants to happen in that case.
 */
trait SerializesModels
{
    /**
     * Replace models with a reference before the payload is written.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $values = [];

        foreach ($this->serializableProperties() as $property) {
            $values[$property->getName()] = $this->toReference(
                $property->isInitialized($this) ? $property->getValue($this) : null
            );
        }

        return $values;
    }

    /**
     * Read the models back when the payload is decoded.
     *
     * @param array<string, mixed> $values
     */
    public function __unserialize(array $values): void
    {
        $reflection = new ReflectionClass($this);

        foreach ($values as $name => $value) {
            if (! $reflection->hasProperty($name)) {
                continue;
            }

            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($this, $this->fromReference($value));
        }
    }

    /**
     * Properties this job carries, excluding static ones.
     *
     * @return array<int, ReflectionProperty>
     */
    private function serializableProperties(): array
    {
        $properties = [];

        foreach ((new ReflectionClass($this))->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $property->setAccessible(true);
            $properties[] = $property;
        }

        return $properties;
    }

    /** Swap a model, or a collection of them, for something small. */
    private function toReference(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return new ModelIdentifier($value::class, $value->getKey());
        }

        if ($value instanceof Collection) {
            $items = $value->all();

            if ($items !== [] && $items[array_key_first($items)] instanceof Model) {
                $first = $items[array_key_first($items)];

                return new ModelIdentifier(
                    $first::class,
                    array_map(static fn (Model $model): mixed => $model->getKey(), $items),
                    collection: true
                );
            }
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->toReference($item), $value);
        }

        return $value;
    }

    /** Read a reference back into the model, or models, it stands for. */
    private function fromReference(mixed $value): mixed
    {
        if ($value instanceof ModelIdentifier) {
            return $value->resolve();
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->fromReference($item), $value);
        }

        return $value;
    }
}
