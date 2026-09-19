<?php

namespace Nitro\Console;

/**
 * The exit codes a console command returns.
 *
 * Named rather than written as literals so a command reads as saying what
 * happened, and so the shell contract has one place to be documented: 0 is the
 * only value a caller may treat as success.
 */
final class ExitCode
{
    /** The command did what it was asked. */
    public const SUCCESS = 0;

    /** The command ran and could not complete its work. */
    public const FAILURE = 1;

    /**
     * The invocation itself was wrong — an unknown signature, a missing
     * argument, a flag that needed a value.
     *
     * Kept distinct from FAILURE because a script can retry a failure and
     * cannot usefully retry a typo.
     */
    public const INVALID = 2;
}
