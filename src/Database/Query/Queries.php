<?php

namespace Nitro\Database\Query;

use ReflectionClass;
use ReflectionMethod;

/**
 * A group of named queries defined as METHODS — the class-based alternative to a
 * closure manifest, and the same idea as grouping relationship methods on a model.
 *
 *   class StudentQueries extends Queries
 *   {
 *       protected string $as = 'students';           // optional prefix (defaults to slug of class)
 *
 *       public function honorRoll()            { return Student::active()->topPerformers(3.8); }
 *       public function inCity(string $city)   { return Student::active()->inCity($city); }
 *   }
 *
 * Each public method becomes a named query "{prefix}.{method}", auto-registered
 * when the file is discovered under app/Queries/. Invoke like any named query:
 *   query('students.honorRoll'), query('students.inCity', ['Lahore']).
 */
abstract class Queries
{
    /** Name prefix for this group. Defaults to a lowercase slug of the class name (minus "Queries"). */
    protected string $as = '';

    /** The prefix these queries register under. */
    public function prefix(): string
    {
        if ($this->as !== '') {
            return $this->as;
        }

        $short = (new ReflectionClass($this))->getShortName();

        return strtolower((string) preg_replace('/Queries$/', '', $short));
    }

    /**
     * The public, non-static query methods declared directly on the concrete
     * class (base-class helpers like prefix() are excluded). @return list<string>
     */
    public function methods(): array
    {
        $methods = [];

        foreach ((new ReflectionClass($this))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $method->isStatic() && $method->getDeclaringClass()->getName() === static::class) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }
}
