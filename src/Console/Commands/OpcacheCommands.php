<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\PathRegistry;

/**
 * Console commands: inspect and clear the PHP opcache.
 */
class OpcacheCommands implements CommandInterface
{
    public function __construct(
        private PathRegistry $paths,
        private ConfigRepository $config,
        private OutputFormatter $output
    ) {}

    public function getCommands(): array
    {
        return [
            'opcache:clear'  => 'Clear the PHP opcache',
            'opcache:status' => 'Show opcache status and statistics',
        ];
    }

    public function handle(string $command, array $arguments): void
    {
        match ($command) {
            'opcache:clear'  => $this->clearOpcache(),
            'opcache:status' => $this->statusOpcache(),
            default          => $this->output->error("Unknown opcache command: {$command}")
        };
    }

    protected function clearOpcache(): void
    {
        $this->output->info("Clearing opcache...");

        if (!function_exists('opcache_reset')) {
            $this->output->warning("Opcache is not enabled on this system.");
            return;
        }

        $paths      = $this->paths;
        $publicPath = $paths->base('public');
        $token      = bin2hex(random_bytes(16));
        $tmpFile    = $publicPath . '/' . $token . '.php';

        file_put_contents($tmpFile, '<?php opcache_reset(); echo "ok";');

        try {
            $appUrl = $this->config->get('app.url');
            $url    = rtrim($appUrl, '/') . '/' . $token . '.php';
            $this->output->info("Hitting: {$url}");
            $result = $this->httpGet($url);

            if ($result === 'ok') {
                $this->output->success("Opcache cleared successfully.");
            } else {
                $this->output->error("Failed to clear opcache. Response: " . ($result ?: 'none'));
            }
        } finally {
            @unlink($tmpFile);
        }
    }

    protected function statusOpcache(): void
    {
        if (!function_exists('opcache_get_status')) {
            $this->output->warning("Opcache extension is not loaded.");
            return;
        }

        $paths      = $this->paths;
        $publicPath = $paths->base('public');
        $token      = bin2hex(random_bytes(16));
        $tmpFile    = $publicPath . '/' . $token . '.php';

        file_put_contents($tmpFile, '<?php echo json_encode(opcache_get_status(false));');

        try {
            $appUrl = $this->config->get('app.url');
            $url    = rtrim($appUrl, '/') . '/' . $token . '.php';
            $this->output->info("Hitting: {$url}");

            try {
                $result = $this->httpGet($url);
            } catch (\RuntimeException $e) {
                $this->output->error("Could not reach web server: " . $e->getMessage());
                $this->output->writeln("  Check app.url in your config (currently: {$appUrl}).");
                return;
            }

            $status = json_decode($result, true);

            if (!$status) {
                $this->output->error("Could not parse opcache status. Raw response: {$result}");
                return;
            }

            $mem   = $status['memory_usage'];
            $stats = $status['opcache_statistics'];

            $this->output->info("Opcache Status:");
            $this->output->writeln("  Enabled:        " . ($status['opcache_enabled'] ? 'Yes' : 'No'));
            $this->output->writeln("  Cached scripts: " . $stats['num_cached_scripts']);
            $this->output->writeln("  Hit rate:       " . round($stats['opcache_hit_rate'], 2) . '%');
            $this->output->writeln("  Memory used:    " . round($mem['used_memory'] / 1024 / 1024, 2) . ' MB');
            $this->output->writeln("  Memory free:    " . round($mem['free_memory'] / 1024 / 1024, 2) . ' MB');
            $this->output->writeln("  Memory wasted:  " . round($mem['wasted_memory'] / 1024 / 1024, 2) . ' MB');
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Fetch a URL. Throws RuntimeException with a descriptive message on
     * transport failure so the caller can distinguish "couldn't reach the
     * web server" from "server responded with non-ok body."
     *
     * @throws \RuntimeException on network failure or HTTP error
     */
    private function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $result = curl_exec($ch);
            $error  = curl_error($ch);
            $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($result === false) {
                throw new \RuntimeException("curl: {$error}");
            }
            if ($code >= 400) {
                throw new \RuntimeException("HTTP {$code} from {$url}");
            }
            return (string) $result;
        }

        // file_get_contents fallback — surface the underlying warning instead
        // of swallowing it with @, so the operator sees why the fetch failed.
        $result = @file_get_contents($url);
        if ($result === false) {
            $err = error_get_last();
            throw new \RuntimeException(
                'file_get_contents failed: ' . ($err['message'] ?? 'unknown error')
            );
        }
        return $result;
    }
}
