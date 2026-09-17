<?php

namespace Nitro\Support;

/**
 * The type returned by immutable_datetime casts.
 *
 * {@see Carbon} is already immutable; this name exists so a model can declare
 * the intent explicitly and so the two cast names resolve to distinct classes.
 */
class CarbonImmutable extends Carbon
{
}
