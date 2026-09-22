<?php

namespace Nitro\Console\Contracts;

/**
 * A command that asks for a required argument rather than failing without it.
 *
 * Only when someone is there to answer: with `--no-interaction`, or when input
 * is not a terminal, the command fails with the same "not enough arguments"
 * error as before. A prompt that appears in CI is a job that hangs until it is
 * killed, which is worse than one that fails in the first second.
 */
interface PromptsForMissingInput
{
    /**
     * What to ask for each argument, keyed by argument name.
     *
     * An argument with no entry is asked for by name. Returning nothing here
     * is fine — the interface alone turns prompting on.
     *
     * @return array<string, string>
     */
    public function promptForMissingArgumentsUsing(): array;
}
