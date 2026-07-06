<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Cache\CacheManager;
use Nitro\Console\OutputFormatter;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Queue\Contracts\FailedJobStore;
use Nitro\Queue\QueueManager;
use Nitro\Queue\QueuedJob;
use Nitro\Queue\Worker;

/**
 * Bundles every queue:* console command. One class per command would
 * sprawl; one bundle matches the MigrationCommands pattern.
 *
 *   queue:work      Run the worker loop. Long-lived; supervisor-managed.
 *   queue:failed    List jobs in the failed store.
 *   queue:retry     Re-dispatch a failed job back to the live queue.
 *   queue:forget    Drop one failed entry.
 *   queue:flush     Drop every failed entry.
 *   queue:restart   Signal running workers to gracefully exit.
 */
class QueueCommands implements CommandInterface
{
    public function __construct(
        private ContainerInterface $container,
        private OutputFormatter $output,
    ) {}

    public function getCommands(): array
    {
        return [
            'queue:work'    => 'Process jobs from the queue (long-running worker)',
            'queue:failed'  => 'List failed jobs',
            'queue:retry'   => 'Retry a failed job by id (or "all")',
            'queue:forget'  => 'Drop a failed job by id',
            'queue:flush'   => 'Drop all failed jobs',
            'queue:restart' => 'Signal running workers to gracefully exit',
        ];
    }

    public function handle(string $command, array $arguments = []): void
    {
        match ($command) {
            'queue:work'    => $this->work($arguments),
            'queue:failed'  => $this->listFailed(),
            'queue:retry'   => $this->retry($arguments),
            'queue:forget'  => $this->forget($arguments),
            'queue:flush'   => $this->flush(),
            'queue:restart' => $this->restart(),
            default         => $this->output->error("Unknown queue command: {$command}"),
        };
    }

    // ── queue:work ────────────────────────────────────────────────────

    private function work(array $arguments): void
    {
        $options = $this->parseWorkOptions($arguments);
        $worker  = $this->container->get(Worker::class);

        $this->output->info(sprintf(
            "Worker started — connection=%s queue=%s sleep=%ds",
            $options['connection'] ?? '(default)',
            $options['queue'],
            $options['sleep'],
        ));
        if ($options['once']) {
            $this->output->writeln('  (mode: --once — exits after one job or empty poll)');
        }

        $options['onJob'] = function (QueuedJob $envelope): void {
            $class = $this->extractClass($envelope->payload);
            $this->output->writeln(sprintf(
                "  processed id=%s queue=%s class=%s attempts=%d",
                $envelope->id ?? '-',
                $envelope->queue,
                $class,
                $envelope->attempts,
            ));
        };

        $worker->run($options);
    }

    private function parseWorkOptions(array $args): array
    {
        $options = [
            'connection' => null,
            'queue'      => 'default',
            'sleep'      => 1,
            'tries'      => null,
            'maxJobs'    => 0,
            'maxTime'    => 0,
            'maxMemory'  => 128,
            'once'       => false,
        ];
        foreach ($args as $arg) {
            if ($arg === '--once') { $options['once'] = true; continue; }
            if (str_starts_with($arg, '--connection=')) { $options['connection'] = substr($arg, 13); continue; }
            if (str_starts_with($arg, '--queue='))      { $options['queue']      = substr($arg, 8);  continue; }
            if (str_starts_with($arg, '--sleep='))      { $options['sleep']      = (int) substr($arg, 8); continue; }
            if (str_starts_with($arg, '--tries='))      { $options['tries']      = (int) substr($arg, 8); continue; }
            if (str_starts_with($arg, '--max-jobs='))   { $options['maxJobs']    = (int) substr($arg, 11); continue; }
            if (str_starts_with($arg, '--max-time='))   { $options['maxTime']    = (int) substr($arg, 11); continue; }
            if (str_starts_with($arg, '--max-memory=')) { $options['maxMemory']  = (int) substr($arg, 13); continue; }
        }
        return $options;
    }

    // ── queue:failed ──────────────────────────────────────────────────

    private function listFailed(): void
    {
        $store = $this->container->get(FailedJobStore::class);
        $rows  = $store->all(50);

        if (empty($rows)) {
            $this->output->success('No failed jobs.');
            return;
        }

        $this->output->info(sprintf("Failed jobs (showing latest %d):\n", count($rows)));
        foreach ($rows as $row) {
            $this->output->writeln(sprintf(
                "  %s  %s  attempts=%d  failed_at=%s",
                $row['id'],
                $row['class'],
                $row['attempts'],
                date('Y-m-d H:i:s', $row['failed_at']),
            ));
            // First line of the exception only — keeps the list scannable.
            $firstLine = strtok($row['exception'], "\n");
            $this->output->writeln("    " . $firstLine);
        }
    }

    // ── queue:retry ───────────────────────────────────────────────────

    private function retry(array $arguments): void
    {
        $id = $arguments[0] ?? null;
        if (!$id) {
            $this->output->error("Usage: queue:retry <id|all>");
            return;
        }

        $store   = $this->container->get(FailedJobStore::class);
        $queues  = $this->container->get(QueueManager::class);

        $targets = $id === 'all' ? $store->all(1000) : array_filter([$store->find($id)]);
        if (empty($targets)) {
            $this->output->error("No failed job with id [{$id}].");
            return;
        }

        $count = 0;
        foreach ($targets as $row) {
            // Re-push the original payload back to the live queue. attempts
            // resets via a fresh QueuedJob — the next worker treats this
            // as a brand-new dispatch.
            $envelope = new QueuedJob(
                id: null,
                queue: $row['queue'],
                payload: $row['payload'],
                attempts: 0,
                availableAt: time(),
                reservedAt: null,
                createdAt: time(),
            );
            $queues->connection()->push($envelope, $row['queue']);
            $store->forget($row['id']);
            $count++;
        }

        $this->output->success("Retried {$count} job(s).");
    }

    // ── queue:forget ──────────────────────────────────────────────────

    private function forget(array $arguments): void
    {
        $id = $arguments[0] ?? null;
        if (!$id) {
            $this->output->error("Usage: queue:forget <id>");
            return;
        }

        $store = $this->container->get(FailedJobStore::class);
        $store->forget($id)
            ? $this->output->success("Forgot failed job {$id}.")
            : $this->output->error("No failed job with id [{$id}].");
    }

    // ── queue:flush ───────────────────────────────────────────────────

    private function flush(): void
    {
        $store = $this->container->get(FailedJobStore::class);
        $n = $store->clear();
        $this->output->success("Cleared {$n} failed job(s).");
    }

    // ── queue:restart ─────────────────────────────────────────────────

    private function restart(): void
    {
        if (!$this->container->has(CacheManager::class)) {
            $this->output->error(
                'queue:restart requires the cache layer. Configure a cache driver in config/cache.php.'
            );
            return;
        }
        $cache = $this->container->get(CacheManager::class);
        // Workers compare this value to what they read at boot; any
        // change means "exit gracefully so the supervisor restarts me."
        $cache->put('queue:restart', time(), 3600);
        $this->output->success('Sent restart signal to running workers.');
    }

    private function extractClass(string $payload): string
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? ($decoded['class'] ?? 'unknown') : 'unknown';
    }
}
