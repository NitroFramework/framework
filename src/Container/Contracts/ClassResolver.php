<?php

namespace Nitro\Container\Contracts;

/**
 * Builds an object from a class name, resolving that class's own dependencies.
 *
 * The one capability a class needs when the type it must instantiate is not
 * known until it runs — a channel authoriser named by class string, a driver
 * registered by an application. Narrower than the container on purpose: a
 * constructor asking for this can create objects and do nothing else, where
 * one asking for the container may bind, alias, forget or enumerate as well.
 */
interface ClassResolver
{
    /**
     * @template TClass of object
     *
     * @param  class-string<TClass> $class
     * @return TClass
     */
    public function resolve(string $class): object;
}
