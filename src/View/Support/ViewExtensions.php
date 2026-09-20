<?php

namespace Nitro\View\Support;

use Nitro\Foundation\Contracts\ConfigRepository;

/**
 * Reads the template extensions out of configuration.
 *
 * `view.extensions` is the list; `view.extension` remains readable as a single
 * value so an application that published its config before there was more than
 * one template language keeps resolving views.
 */
final class ViewExtensions
{
    /**
     * @return array<int, string>
     */
    public static function from(ConfigRepository $config): array
    {
        $extensions = self::normalise($config->get('view.extensions', []));
        $single     = $config->get('view.extension');

        /*
         * A single configured extension goes first rather than replacing the
         * list, so an application that renamed its templates before there was
         * more than one template language still finds them.
         */
        if (is_string($single) && $single !== '') {
            array_unshift($extensions, $single);
        }

        return self::normalise($extensions);
    }

    /**
     * @param  mixed $configured
     * @return array<int, string>
     */
    public static function normalise(mixed $configured): array
    {
        $extensions = [];

        foreach ((array) $configured as $extension) {
            if (! is_string($extension)) {
                continue;
            }

            $extension = ltrim($extension, '.');

            if ($extension !== '' && ! in_array($extension, $extensions, true)) {
                $extensions[] = $extension;
            }
        }

        return $extensions === [] ? ['blade.php'] : $extensions;
    }

    /**
     * The longest of the given extensions a file ends with, or null for none.
     *
     * @param array<int, string> $extensions
     */
    public static function match(string $file, array $extensions): ?string
    {
        $file    = strtolower($file);
        $matched = null;

        foreach ($extensions as $extension) {
            if (! str_ends_with($file, '.' . strtolower($extension))) {
                continue;
            }

            if ($matched === null || strlen($extension) > strlen($matched)) {
                $matched = $extension;
            }
        }

        return $matched;
    }

    /**
     * Take the template extension off a path, leaving the view name behind.
     *
     * @param array<int, string> $extensions
     */
    public static function strip(string $path, array $extensions): string
    {
        $matched = self::match($path, $extensions);

        return $matched === null ? $path : substr($path, 0, -(strlen($matched) + 1));
    }
}
