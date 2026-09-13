<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Session\SessionManager;

/**
 * Session maintenance.
 *
 * `session:gc` sweeps session payloads that have been idle past the configured
 * lifetime. The handlers have always implemented gc(); until now nothing ever
 * called it, so a file-driver install grew an unbounded pile — one dead file
 * for every client that never returns the cookie (health checks, crawlers,
 * curl, load generators).
 *
 * Deliberately a command rather than Laravel's per-request lottery. Laravel can
 * afford an inline sweep because FPM gives each request its own process; under
 * Thrust a worker serves requests in a loop, so a sweep that walks a large
 * session directory blocks every request queued behind it. Measured on an
 * 8-worker FrankenPHP run with ~24k session files, a [2,100] inline lottery
 * took a plain JSON route from 5,913 req/s to 754 req/s with p95 latency
 * crossing 300ms. Off the request path it costs nothing and can walk the whole
 * directory properly.
 *
 * Run it from cron, or from the scheduler:
 *
 *     $schedule->command('session:gc')->hourly();
 */
class SessionCommands implements CommandInterface
{
    public function __construct(
        private SessionManager $sessions,
        private OutputFormatter $output,
    ) {}

    public function getCommands(): array
    {
        return [
            'session:gc' => 'Delete session payloads idle past the configured lifetime',
        ];
    }

    public function handle(string $signature, array $arguments): void
    {
        $lifetime = (int) (config('session.lifetime') ?? 120);

        // Accept --minutes=N to sweep more aggressively than the session
        // lifetime (e.g. clearing a backlog) without editing config.
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--minutes=')) {
                $lifetime = max(0, (int) substr($argument, 10));
            }
        }

        $driver = $this->sessions->driver();
        $driver->collectGarbage($lifetime);

        $this->output->writeln("Swept sessions idle for more than {$lifetime} minute(s).");
    }
}
