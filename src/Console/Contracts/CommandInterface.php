<?php

namespace Nitro\Console\Contracts;

/**
 * Contract for a console command class.
 *
 * A class exposes one or more command signatures via getCommands() and executes
 * whichever one was invoked in handle(). Grouping cohesive commands (e.g. every
 * migrate:* verb) in a single class is intentional — they share helpers and read
 * as one unit; unrelated commands belong in separate classes.
 */
interface CommandInterface
{
    /**
     * The command signatures this class handles, mapped to their descriptions
     * (shown in `php nitro help`).
     *
     * @return array<string, string> signature => description
     */
    public function getCommands(): array;

    /**
     * Execute the invoked command.
     *
     * @param string             $signature The signature that was invoked.
     * @param array<int, string> $arguments CLI arguments after the signature.
     */
    public function handle(string $signature, array $arguments): void;
}
