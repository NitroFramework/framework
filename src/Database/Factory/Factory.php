<?php

namespace Nitro\Database\Factory;

use Nitro\Database\Model\BaseModel;

/**
 * Base class for model factories. Laravel-shaped:
 *
 *   class UserFactory extends Factory {
 *       protected string $model = User::class;
 *
 *       public function definition(): array {
 *           return [
 *               'name'     => $this->faker->name(),
 *               'email'    => $this->faker->unique()->email(),
 *               'password' => password_hash('secret', PASSWORD_DEFAULT),
 *           ];
 *       }
 *
 *       public function admin(): self {
 *           return $this->state(['is_admin' => true]);
 *       }
 *   }
 *
 *   User::factory()->create();
 *   User::factory()->count(10)->create();
 *   User::factory()->admin()->create();
 *   User::factory()->state(['status' => 'banned'])->make();
 *
 * make() returns a model (or array of models) without persisting; create()
 * inserts and returns the persisted instance(s). states layer on top of
 * the base definition() in order; explicit overrides passed to
 * make()/create() win over both.
 */
abstract class Factory
{
    /** Fully-qualified model class this factory produces. */
    protected string $model;

    /** How many instances each terminal call should produce. */
    protected int $count = 1;

    /** Stacked state mutators applied on top of definition(). */
    protected array $states = [];

    /** Shared fake-data generator — exposed as $this->faker in definition(). */
    public Generator $faker;

    public function __construct()
    {
        $this->faker = new Generator();
    }

    /**
     * Return the base attribute map for one record. Subclasses override
     * this; everything else (count, state, make, create) layers on top.
     */
    abstract public function definition(): array;

    /**
     * Set how many records the next make()/create() should produce.
     * Returns a CLONE so callers don't accidentally mutate a shared
     * factory instance — every chain is independent.
     */
    public function count(int $count): static
    {
        $clone = clone $this;
        $clone->count = max(1, $count);
        return $clone;
    }

    /**
     * Layer a state on top of definition(). Pass an array of overrides
     * or a callable that receives the merged-so-far attributes and
     * returns more overrides.
     *
     *   ->state(['status' => 'active'])
     *   ->state(fn (array $attrs) => ['slug' => str_slug($attrs['name'])])
     */
    public function state(array|callable $state): static
    {
        $clone = clone $this;
        $clone->states[] = $state;
        return $clone;
    }

    /**
     * Build model instance(s) without persisting. Returns a single
     * instance when count is 1, an array otherwise — matches Laravel's
     * shape so test code reads identically.
     *
     * @return BaseModel|array<int, BaseModel>
     */
    public function make(array $attributes = []): BaseModel|array
    {
        if ($this->count > 1) {
            return $this->seq($this->count, fn() => $this->makeOne($attributes));
        }
        return $this->makeOne($attributes);
    }

    /**
     * Build AND persist model instance(s).
     *
     * @return BaseModel|array<int, BaseModel>
     */
    public function create(array $attributes = []): BaseModel|array
    {
        if ($this->count > 1) {
            return $this->seq($this->count, fn() => $this->createOne($attributes));
        }
        return $this->createOne($attributes);
    }

    /**
     * Build the raw attribute map without instantiating the model.
     * Useful for assertions, JSON fixtures, or for callers that handle
     * persistence themselves.
     *
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    public function raw(array $attributes = []): array
    {
        if ($this->count > 1) {
            return $this->seq($this->count, fn() => $this->mergeStates($attributes));
        }
        return $this->mergeStates($attributes);
    }

    private function makeOne(array $overrides): BaseModel
    {
        $attrs = $this->mergeStates($overrides);
        $class = $this->resolveModelClass();
        /** @var BaseModel $instance */
        $instance = new $class();
        $instance->fill($attrs);
        return $instance;
    }

    private function createOne(array $overrides): BaseModel
    {
        $attrs = $this->mergeStates($overrides);
        $class = $this->resolveModelClass();
        return $class::create($attrs);
    }

    /**
     * Compose the final attribute map for one record: definition() →
     * each registered state in order → explicit overrides. States may
     * be callables — they receive the merged-so-far attributes and
     * return more overrides to layer on top.
     */
    private function mergeStates(array $overrides): array
    {
        $attrs = $this->definition();
        foreach ($this->states as $state) {
            $resolved = is_callable($state) ? $state($attrs) : $state;
            if (!is_array($resolved)) {
                throw new \RuntimeException(
                    'Factory state must return an array of attribute overrides.'
                );
            }
            $attrs = array_merge($attrs, $resolved);
        }
        return array_merge($attrs, $overrides);
    }

    private function resolveModelClass(): string
    {
        if (!isset($this->model) || $this->model === '') {
            throw new \RuntimeException(
                static::class . ' must declare a $model property '
                . '(e.g. protected string $model = User::class;)'
            );
        }
        if (!class_exists($this->model)) {
            throw new \RuntimeException(
                static::class . ' references unknown model class: ' . $this->model
            );
        }
        return $this->model;
    }

    private function seq(int $count, callable $producer): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $producer();
        }
        return $out;
    }
}
