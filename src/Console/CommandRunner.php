<?php

namespace Nitro\Console;

/**
 * Runs a command by name, for a command that needs to run another.
 *
 * Narrower than the command manager on purpose: a command that calls one other
 * command should not also be able to register, list or introspect them.
 */
interface CommandRunner
{
    /**
     * Run $command with $arguments and return its exit code.
     *
     * @param array<int, string> $arguments Arguments as they would be typed.
     */
    public function call(string $command, array $arguments = [], Verbosity $verbosity = Verbosity::Normal): int;
}
