<?php

namespace Nitro\Console\Contracts;

/**
 * A command that must not run twice at once.
 *
 * Marking a command with this makes `--isolated` available on it; the flag is
 * what actually asks for the lock, so an interactive run is unaffected and a
 * scheduled one opts in:
 *
 *   php nitro reports:build --isolated
 *
 * A second run that finds the lock held exits without doing anything, and says
 * so. That is a success from the scheduler's point of view — the work is in
 * hand — so it returns 0 unless the command says otherwise.
 */
interface Isolatable
{
    /**
     * How long the lock survives if the process holding it dies.
     *
     * Long enough to cover the command's slowest honest run: expire too soon
     * and a second copy starts while the first is still working, which is the
     * thing the lock exists to prevent.
     */
    public function isolationSeconds(): int;
}
