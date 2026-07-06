<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PathRegistry;

/**
 * Runs the application on PHP's built-in development server, the quick
 * `php nitro serve` local loop (mirrors `php artisan serve`). A small router
 * stub serves existing public/ assets verbatim and routes everything else
 * through the front controller so pretty URLs work without Apache/nginx.
 *
 * For the high-performance persistent worker (FrankenPHP), use the Thrust
 * layer instead: `php nitro thrust:start`.
 */
class ServeCommand implements CommandInterface
{
    public function __construct(
        private PathRegistry $paths,
        private OutputFormatter $output,
    ) {}

    public function getCommands(): array
    {
        return [
            'serve' => 'Run the app on the PHP development server',
        ];
    }

    public function handle(string $command, array $arguments): void
    {
        [$host, $port] = $this->parseHostPort($arguments);

        $public = $this->paths->base() . DIRECTORY_SEPARATOR . 'public';
        $router = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'server.php';

        if (!is_file($public . DIRECTORY_SEPARATOR . 'index.php')) {
            $this->output->writeln($this->output->color("✖ Front controller not found at {$public}/index.php", 'red'));
            return;
        }

        $this->output->writeln("");
        $this->output->writeln($this->output->color("  Nitro development server", 'cyan', true));
        $this->output->writeln("  " . $this->output->color("➜", 'green') . "  http://{$host}:{$port}");
        $this->output->writeln("  " . $this->output->color("Press Ctrl+C to stop", 'yellow'));
        $this->output->writeln("");

        // php -S serves static files from -t docroot; the router stub falls
        // back to public/index.php for everything else. PHP_BINARY is the same
        // interpreter running this command, so the child matches our version.
        $cmd = [
            PHP_BINARY,
            '-S', "{$host}:{$port}",
            '-t', $public,
            $router,
        ];

        $env = ['NITRO_PUBLIC_PATH' => $public] + $this->scalarEnv();

        $process = proc_open($cmd, [STDIN, STDOUT, STDERR], $pipes, $this->paths->base(), $env);
        if (!is_resource($process)) {
            $this->output->writeln($this->output->color("✖ Failed to start the development server", 'red'));
            return;
        }

        $this->forwardSignalsTo($process);

        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(100_000);
        } while (true);

        exit(proc_close($process));
    }

    /**
     * Parse --host=NAME / --port=NNNN out of the raw arguments, defaulting to
     * the conventional local dev host/port.
     */
    private function parseHostPort(array $arguments): array
    {
        $host = '127.0.0.1';
        $port = 8000;
        foreach ($arguments as $arg) {
            if (str_starts_with($arg, '--host=')) {
                $host = substr($arg, 7);
            } elseif (str_starts_with($arg, '--port=')) {
                $port = (int) substr($arg, 7);
            }
        }
        return [$host, $port];
    }

    /**
     * A flat string=>string slice of the parent env for the child process;
     * nested arrays (e.g. $_SERVER['argv']) and resources are dropped so
     * proc_open doesn't choke on "Array to string conversion".
     */
    private function scalarEnv(): array
    {
        $env = [];
        foreach ($_ENV + $_SERVER as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $env[(string) $key] = (string) ($value ?? '');
            }
        }
        return $env;
    }

    /**
     * Forward SIGINT/SIGTERM to the child so Ctrl+C shuts the server down
     * cleanly. No-op on platforms without pcntl (native Windows PHP).
     */
    private function forwardSignalsTo($process): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = static function () use ($process): void {
            proc_terminate($process);
        };
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }
}
