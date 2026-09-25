<?php

if (! defined('NITRO_PROFILE')) {
    /**
     * Whether any timing instrumentation runs in this process.
     *
     * True when NITRO_PROFILE or NITRO_TIMELINE is set in the real environment,
     * or when the request asks for ?timeline. Every BootProfile and Timeline
     * call site checks this first, so with it false neither class loads and
     * no mark's label is built.
     *
     * Decided here because this file loads before anything else, which the
     * first mark needs. That is also why .env cannot switch it: .env is read
     * during bootstrap, after the first mark.
     */
    define('NITRO_PROFILE', (static function (): bool {
        foreach (['NITRO_PROFILE', 'NITRO_TIMELINE'] as $name) {
            $flag = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

            if ($flag !== false && $flag !== null && $flag !== '' && $flag !== '0' && $flag !== 'false') {
                return true;
            }
        }

        return isset($_GET['timeline']);
    })());
}
