<?php

namespace Nitro\Console\Events;

/**
 * Events the console layer raises.
 *
 * Declared here rather than in a framework-wide catalogue, so the layer owns
 * its own vocabulary: delete the console and its events go with it, and a
 * package adding a layer names its events without editing anything in core.
 *
 * Both carry a {@see CommandEvent}.
 */
class ConsoleEvents
{
    /** A command has been built and is about to run. */
    const STARTING = 'command.starting';

    /** The command returned, whatever its exit code. */
    const FINISHED = 'command.finished';
}
