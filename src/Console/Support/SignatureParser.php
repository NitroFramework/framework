<?php

namespace Nitro\Console\Support;

use InvalidArgumentException;

/**
 * Parses a Laravel-style command signature into its name plus argument and
 * option definitions. Mirrors Laravel's token syntax (leanly, without Symfony):
 *
 *   name:verb {arg} {arg?} {arg=default} {arg*} {arg?*}
 *             {--flag} {--opt=} {--opt=default} {--opt=*} {--s|opt}
 *             {arg : a description} {--opt : a description}
 *
 * Argument/option definitions are plain arrays consumed by Command::bind().
 */
class SignatureParser
{
    /**
     * @return array{name: string, arguments: list<array>, options: list<array>}
     */
    public static function parse(string $signature): array
    {
        $name = self::name($signature);
        $arguments = [];
        $options = [];

        if (preg_match_all('/\{\s*(.*?)\s*\}/', $signature, $matches)) {
            foreach ($matches[1] as $token) {
                if (preg_match('/^-{2,}(.*)/', $token, $m)) {
                    $options[] = self::parseOption($m[1]);
                } else {
                    $arguments[] = self::parseArgument($token);
                }
            }
        }

        return ['name' => $name, 'arguments' => $arguments, 'options' => $options];
    }

    /** The command name is the first non-space token. */
    protected static function name(string $signature): string
    {
        if (! preg_match('/[^\s]+/', $signature, $m)) {
            throw new InvalidArgumentException('Unable to determine command name from signature.');
        }

        return $m[0];
    }

    /** @return array{name: string, mode: string, default: mixed, description: string} */
    protected static function parseArgument(string $token): array
    {
        [$token, $description] = self::extractDescription($token);

        return match (true) {
            str_ends_with($token, '?*') => ['name' => trim($token, '?*'), 'mode' => 'array',          'default' => [],   'description' => $description],
            str_ends_with($token, '*')  => ['name' => trim($token, '*'),  'mode' => 'array_required', 'default' => [],   'description' => $description],
            str_ends_with($token, '?')  => ['name' => trim($token, '?'),  'mode' => 'optional',       'default' => null, 'description' => $description],
            (bool) preg_match('/(.+)=(.+)/', $token, $m) => ['name' => $m[1], 'mode' => 'optional', 'default' => $m[2], 'description' => $description],
            default => ['name' => $token, 'mode' => 'required', 'default' => null, 'description' => $description],
        };
    }

    /** @return array{name: string, shortcut: ?string, mode: string, default: mixed, description: string} */
    protected static function parseOption(string $token): array
    {
        [$token, $description] = self::extractDescription($token);

        $shortcut = null;
        $parts = preg_split('/\s*\|\s*/', $token, 2);
        if (isset($parts[1])) {
            $shortcut = $parts[0];
            $token = $parts[1];
        }

        return match (true) {
            str_ends_with($token, '=*') => ['name' => trim($token, '=*'), 'shortcut' => $shortcut, 'mode' => 'array', 'default' => [],   'description' => $description],
            str_ends_with($token, '=')  => ['name' => trim($token, '='),  'shortcut' => $shortcut, 'mode' => 'value', 'default' => null, 'description' => $description],
            (bool) preg_match('/(.+)=(.+)/', $token, $m) => ['name' => $m[1], 'shortcut' => $shortcut, 'mode' => 'value', 'default' => $m[2], 'description' => $description],
            default => ['name' => $token, 'shortcut' => $shortcut, 'mode' => 'none', 'default' => false, 'description' => $description],
        };
    }

    /** Split a token into [token, description] on " : ". */
    protected static function extractDescription(string $token): array
    {
        $parts = preg_split('/\s+:\s+/', trim($token), 2);

        return count($parts) === 2 ? $parts : [$token, ''];
    }
}
