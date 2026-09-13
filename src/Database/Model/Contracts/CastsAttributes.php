<?php

namespace Nitro\Database\Model\Contracts;

use Nitro\Database\Model\Model;

/**
 * A custom attribute cast: the object form of a `$casts` entry.
 *
 * Where the built-in casts ('int', 'array', 'datetime', …) are named by string,
 * a class implementing this interface is named by class-string and owns both
 * directions of the conversion:
 *
 *     protected array $casts = ['blocks' => BlockCollection::class];
 *
 * get() turns the stored column into the rich value the application works with;
 * set() turns that value back into something PDO can bind. The pair must round
 * trip — whatever set() writes, get() has to be able to read back.
 *
 * Implementations are resolved once per (class, cast) and reused for every model
 * of that class, so they MUST be stateless: keep per-attribute data in the value
 * you return, never on the caster.
 *
 * A cast declared with arguments ('price' => Money::class . ':GBP') receives them
 * as constructor arguments, so a parameterised caster takes them in __construct.
 */
interface CastsAttributes
{
    /**
     * Transform the raw stored value into its application representation.
     *
     * @param  array<string, mixed>  $attributes  The model's other raw attributes,
     *                                            for casts that read more than one
     *                                            column (a money amount plus its
     *                                            currency, say).
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed;

    /**
     * Transform the application value into what should be written to the column.
     *
     * Return a scalar (or null) for a single-column cast. A cast that spans
     * several columns returns an array of column => value, which is merged into
     * the model's attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return mixed|array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed;
}
