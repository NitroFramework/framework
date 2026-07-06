<?php

namespace Nitro\Thrust\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PathRegistry;
use Nitro\Thrust\FrankenPhp\BinaryFinder;
use Nitro\Thrust\FrankenPhp\BinaryInstaller;
use Nitro\Thrust\FrankenPhp\ProcessInspector;
use Nitro\Thrust\FrankenPhp\ServerStateFile;

/**
 * The Thrust command surface — Nitro's persistent FrankenPHP worker server,
 * modelled on Laravel Octane's octane:* commands:
 *
 *   thrust:install   Scaffold the worker + Caddyfile and install the binary
 *   thrust:start     Boot the FrankenPHP worker (--server=frankenphp)
 *   thrust:stop      Stop a running server
 *   thrust:status    Report whether a server is running
 *   thrust:reload    Gracefully reload the workers (new code, warm server)
 *
 * FrankenPHP is the supported server; the flag mirrors Octane so `--server=`
 * reads the same, leaving room for other runtimes later.
 */
class ThrustCommands implements CommandInterface
{
    public function __construct(
        private PathRegistry $paths,
        private OutputFormatter $output,
    ) {}

    public function getCommands(): array
    {
        return [
            'thrust:install' => 'Install the FrankenPHP worker server (binary + config)',
            'thrust:start'   => 'Start the FrankenPHP worker server (--host --port --workers --watch)',
            'thrust:stop'    => 'Stop the running worker server',
            'thrust:status'  => 'Show whether the worker server is running',
            'thrust:reload'  => 'Gracefully reload the worker server',
        ];
    }

    public function handle(string $command, array $arguments): void
    {
        $options = $this->parseOptions($arguments);
        $server = $options['server'] ?? 'frankenphp';

        if ($server !== 'frankenphp') {
            $this->output->writeln($this->output->color(
                "✖ Thrust currently supports --server=frankenphp (got '{$server}').",
                'red'
            ));
            return;
        }

        match ($command) {
            'thrust:install' => $this->install($options),
            'thrust:start'   => $this->start($options),
            'thrust:stop'    => $this->stop(),
            'thrust:status'  => $this->status(),
            'thrust:reload'  => $this->reload(),
            default          => $this->output->writeln("Unknown command: {$command}"),
        };
    }

    // ─── install ──────────────────────────────────────────────────────────────

    private function install(array $options): void
    {
        $base = $this->paths->base();
        $force = isset($options['force']);

        $this->copyStub('worker.php', $base . '/public/worker.php', $force);
        $this->copyStub('Caddyfile', $base . '/Caddyfile', $force);
        $this->ensureGitignored('/frankenphp');

        $finder = new BinaryFinder($base);
        if (($binary = $finder->find()) !== null) {
            $this->output->writeln($this->output->color("✔ FrankenPHP binary found: {$binary}", 'green'));
            $this->maybeScaffoldPhpIni($binary);
            return;
        }

        $installer = new BinaryInstaller($base);
        if (! $installer->isSupportedPlatform()) {
            $this->printWindowsGuidance();
            return;
        }

        $this->output->writeln("Downloading the FrankenPHP binary…");
        try {
            $path = $installer->download(function (int $done, int $total): void {
                if ($total > 0) {
                    printf("\r  %d%% (%d/%d bytes)", (int) ($done / $total * 100), $done, $total);
                }
            });
            $this->output->writeln("");
            $this->output->writeln($this->output->color("✔ Installed FrankenPHP to {$path}", 'green'));
            $this->maybeScaffoldPhpIni($path);
        } catch (\Throwable $e) {
            $this->output->writeln("");
            $this->output->writeln($this->output->color("✖ Download failed: {$e->getMessage()}", 'red'));
        }
    }

    // ─── start ────────────────────────────────────────────────────────────────

    private function start(array $options): void
    {
        $base = $this->paths->base();
        $finder = new BinaryFinder($base);
        $binary = $finder->find();

        if ($binary === null) {
            $this->output->writeln($this->output->color("✖ FrankenPHP binary not found.", 'red', true));
            $this->output->writeln("Run " . $this->output->color("php nitro thrust:install", 'green') . " first.");
            return;
        }

        $stateFile = $this->stateFile();
        $inspector = new ProcessInspector($stateFile);
        if ($inspector->serverIsRunning()) {
            $this->output->writeln($this->output->color("✖ A Thrust server is already running.", 'red'));
            $this->output->writeln("Use " . $this->output->color("php nitro thrust:reload", 'green') . " to reload it.");
            return;
        }

        $caddyfile = $base . DIRECTORY_SEPARATOR . 'Caddyfile';
        if (! is_file($caddyfile)) {
            $this->output->writeln($this->output->color("✖ Caddyfile not found — run php nitro thrust:install.", 'red'));
            return;
        }

        $host = $options['host'] ?? '127.0.0.1';
        $port = (int) ($options['port'] ?? 8080);
        $workers = (int) ($options['workers'] ?? 1);
        $adminHost = 'localhost';
        $adminPort = (int) ($options['admin-port'] ?? 2019);

        $stateFile->writeState([
            'host' => $host, 'port' => $port,
            'adminHost' => $adminHost, 'adminPort' => $adminPort,
            'workers' => $workers,
        ]);

        $env = [
            // Bind plain HTTP on all interfaces for the port (Octane's approach).
            // Caddy treats a bare host:port as HTTPS, so the scheme is explicit
            // and both localhost and 127.0.0.1 resolve.
            'SERVER_NAME' => "http://:{$port}",
            'SERVER_PORT' => (string) $port,
            'THRUST_ADMIN_ENDPOINT' => "{$adminHost}:{$adminPort}",
            'THRUST_WORKERS' => (string) $workers,
            'THRUST_WATCH_DIRECTIVE' => isset($options['watch']) ? 'watch' : '',
        ] + $this->scalarEnv();

        $this->output->writeln("");
        $this->output->writeln($this->output->color("⚡ Thrust — FrankenPHP worker server", 'cyan', true));
        $this->output->writeln("  " . $this->output->color("➜", 'green') . "  http://{$host}:{$port}");
        $this->output->writeln("  workers: {$workers}    admin: {$adminHost}:{$adminPort}"
            . (isset($options['watch']) ? "    watch: on" : ""));
        $this->output->writeln("  " . $this->output->color("Press Ctrl+C to stop", 'yellow'));
        $this->output->writeln("");

        $process = proc_open(
            [$binary, 'run', '--config', $caddyfile],
            [STDIN, STDOUT, STDERR],
            $pipes,
            $base,
            $env,
        );

        if (! is_resource($process)) {
            $this->output->writeln($this->output->color("✖ Failed to start FrankenPHP.", 'red'));
            $stateFile->delete();
            return;
        }

        $status = proc_get_status($process);
        if (($status['pid'] ?? 0) > 0) {
            $stateFile->writeProcessId((int) $status['pid']);
        }

        $this->forwardSignalsTo($process);

        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }
            usleep(100_000);
        } while (true);

        $exit = proc_close($process);
        $stateFile->delete();
        exit($exit);
    }

    // ─── stop / status / reload ─────────────────────────────────────────────────

    private function stop(): void
    {
        $stateFile = $this->stateFile();
        $inspector = new ProcessInspector($stateFile);

        if (! $inspector->serverIsRunning()) {
            $this->output->writeln($this->output->color("Thrust server is not running.", 'yellow'));
            $stateFile->delete();
            return;
        }

        if ($inspector->stopServer()) {
            $stateFile->delete();
            $this->output->writeln($this->output->color("✔ Thrust server stopped.", 'green'));
        } else {
            $this->output->writeln($this->output->color("✖ Could not stop the server.", 'red'));
        }
    }

    private function status(): void
    {
        $stateFile = $this->stateFile();
        $inspector = new ProcessInspector($stateFile);

        if ($inspector->serverIsRunning()) {
            $state = $stateFile->read()['state'] ?? [];
            $where = isset($state['host'], $state['port']) ? " (http://{$state['host']}:{$state['port']})" : '';
            $this->output->writeln($this->output->color("● Thrust server is running{$where}.", 'green'));
        } else {
            $this->output->writeln($this->output->color("○ Thrust server is not running.", 'yellow'));
        }
    }

    private function reload(): void
    {
        $inspector = new ProcessInspector($this->stateFile());

        if (! $inspector->serverIsRunning()) {
            $this->output->writeln($this->output->color("✖ Thrust server is not running.", 'red'));
            return;
        }

        if ($inspector->reloadServer()) {
            $this->output->writeln($this->output->color("✔ Thrust workers reloaded.", 'green'));
        } else {
            $this->output->writeln($this->output->color(
                "✖ Reload failed — is the admin API enabled in your Caddyfile?",
                'red'
            ));
        }
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function stateFile(): ServerStateFile
    {
        return new ServerStateFile(
            $this->paths->base() . '/storage/framework/thrust-server-state.json'
        );
    }

    /**
     * Split raw args into an options map: --flag → true, --key=value → 'value'.
     */
    private function parseOptions(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $arg) {
            if (! str_starts_with($arg, '--')) {
                continue;
            }
            $body = substr($arg, 2);
            $eq = strpos($body, '=');
            if ($eq === false) {
                $options[$body] = true;
            } else {
                $options[substr($body, 0, $eq)] = substr($body, $eq + 1);
            }
        }
        return $options;
    }

    private function copyStub(string $stub, string $target, bool $force): void
    {
        if (is_file($target) && ! $force) {
            return;
        }
        $source = __DIR__ . '/../stubs/' . $stub;
        if (! is_file($source)) {
            return;
        }
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        copy($source, $target);
        $this->output->writeln($this->output->color("✔ Wrote " . basename($target), 'green'));
    }

    /**
     * FrankenPHP's bundled Windows PHP ships without an active php.ini, so it
     * loads zero PDO drivers and every DB query dies with "could not find
     * driver". Drop a php.ini next to the binary enabling pdo_mysql + common
     * extensions so worker mode can reach the database out of the box.
     */
    private function maybeScaffoldPhpIni(string $binary): void
    {
        $ini = self::scaffoldPhpIni(dirname($binary));
        if ($ini === null) {
            return;
        }

        $this->output->writeln($this->output->color("✔ Wrote {$ini}", 'green'));
        $this->output->writeln("  Enabled pdo_mysql + common extensions. Restart a running server");
        $this->output->writeln("  (stop + start — reload won't re-init PHP) so the worker loads it.");
    }

    /**
     * Write a php.ini beside a bundled FrankenPHP binary that lacks one.
     * Returns the path written, or null when nothing is needed — a static
     * build (no ext/ dir, extensions compiled in) or an ini already present.
     * Public + static so it's unit-testable without booting a command.
     */
    public static function scaffoldPhpIni(string $binaryDir): ?string
    {
        $extDir = $binaryDir . DIRECTORY_SEPARATOR . 'ext';
        $ini = $binaryDir . DIRECTORY_SEPARATOR . 'php.ini';

        if (! is_dir($extDir) || is_file($ini)) {
            return null;
        }

        return file_put_contents($ini, self::phpIniStub($extDir)) !== false ? $ini : null;
    }

    private static function phpIniStub(string $extDir): string
    {
        $dir = str_replace('\\', '/', $extDir);

        return <<<INI
            ; PHP configuration for the FrankenPHP worker runtime (Nitro Thrust).
            ; FrankenPHP's bundled PHP ships without an active php.ini, so PDO
            ; drivers and other dynamic extensions must be enabled here.

            extension_dir = "{$dir}"

            ; Database drivers (Nitro talks to MySQL via PDO).
            extension=pdo_mysql
            extension=pdo_sqlite

            ; Common extensions apps expect.
            extension=mbstring
            extension=openssl
            extension=fileinfo
            extension=curl
            extension=intl

            INI;
    }

    private function ensureGitignored(string $entry): void
    {
        $gitignore = $this->paths->base() . '/.gitignore';
        $contents = is_file($gitignore) ? file_get_contents($gitignore) : '';
        if (! preg_match('/^' . preg_quote($entry, '/') . '\s*$/m', $contents)) {
            file_put_contents($gitignore, rtrim($contents) . "\n{$entry}\n");
        }
    }

    private function printWindowsGuidance(): void
    {
        $this->output->writeln("");
        $this->output->writeln($this->output->color("FrankenPHP on Windows", 'yellow', true));
        $this->output->writeln("Thrust's auto-download covers Linux/macOS, but FrankenPHP now has native");
        $this->output->writeln("Windows support — install it once, then re-run thrust:install:");
        $this->output->writeln("");
        $this->output->writeln("  " . $this->output->color("irm https://frankenphp.dev/install.ps1 | iex", 'green'));
        $this->output->writeln("");
        $this->output->writeln("Put frankenphp.exe on your PATH or in the project root, then:");
        $this->output->writeln("  " . $this->output->color("php nitro thrust:start", 'green'));
        $this->output->writeln("");
        $this->output->writeln("Prefer isolation? Use WSL (thrust:install then downloads the Linux binary)");
        $this->output->writeln("or Docker: " . $this->output->color("docker run -v \${PWD}:/app -p 8080:8080 dunglas/frankenphp", 'green'));
        $this->output->writeln("");
    }

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

    private function forwardSignalsTo($process): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
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
