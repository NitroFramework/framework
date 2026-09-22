<?php

namespace Nitro\Console\Support;

/**
 * Turns the arguments a caller writes into the arguments a command line carries.
 */
final class Arguments
{
    /**
     * Flatten named arguments into the form a command line would carry.
     *
     *   ['--force' => true]   -> ['--force']
     *   ['--queue' => 'high'] -> ['--queue=high']
     *   ['--id' => [1, 2]]    -> ['--id=1', '--id=2']
     *   ['user' => 5]         -> ['5']
     *   ['--force']           -> ['--force']
     *
     * A plain list is already in that form and passes through unchanged, so a
     * caller may write either spelling without knowing which one is expected.
     * A named option given `false` or `null` is dropped rather than passed as
     * an empty flag, since that is what "do not set this" means.
     *
     * @param  array<array-key, mixed> $arguments
     * @return array<int, string>
     */
    public static function flatten(array $arguments): array
    {
        $flat = [];

        foreach ($arguments as $key => $value) {
            if (is_int($key)) {
                $flat[] = (string) $value;

                continue;
            }

            if ($value === false || $value === null) {
                continue;
            }

            if ($value === true) {
                $flat[] = $key;

                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $item) {
                $flat[] = str_starts_with($key, '-')
                    ? $key . '=' . $item
                    : (string) $item;
            }
        }

        return $flat;
    }

    /**
     * The same arguments with the given flags removed.
     *
     * For flags the dispatcher owns — `--isolated`, `--no-interaction` — which
     * would otherwise reach a command's signature parser as options it never
     * declared.
     *
     * @param  array<int, string> $arguments
     * @param  array<int, string> $flags
     * @return array<int, string>
     */
    public static function without(array $arguments, array $flags): array
    {
        return array_values(array_filter(
            $arguments,
            static fn (string $argument): bool => ! in_array($argument, $flags, true)
        ));
    }
}
