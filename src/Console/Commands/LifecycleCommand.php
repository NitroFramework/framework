<?php

namespace Nitro\Console\Commands;

use Closure;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\Application;
use Nitro\Http\Kernel;
use ReflectionFunction;

/**
 * Print the application's execution lifecycle: what runs, in what order, and
 * — for the parts attached at runtime — from where.
 *
 * Nitro composes most behaviour indirectly. A provider's boot() can add a
 * middleware, define a macro, or attach a kernel hook, and none of that is
 * visible at the seam where it later runs: reading Kernel::handle() shows a
 * runHooks() call against an array, with no way to tell what is in it short of
 * grepping the whole framework. That opacity is not academic — it is how a
 * session came to be started before routing on *every* request, costing a
 * plain JSON route more than half its throughput, without appearing in the
 * kernel or in any middleware group.
 *
 * This command answers that question from the live container instead of from
 * documentation, so it cannot drift from the code. It is the execution-order
 * counterpart to `route:list`.
 */
class LifecycleCommand implements CommandInterface
{
    public function __construct(
        private Application $app,
        private Kernel $kernel,
        private OutputFormatter $output,
    ) {}

    public function getCommands(): array
    {
        return [
            'lifecycle' => 'Show the boot + request lifecycle: order, providers, middleware, hooks',
        ];
    }

    public function handle(string $signature, array $arguments): void
    {
        $this->heading('PHASE 1 — BOOT');
        $this->output->writeln('  Runs ONCE per process. Under Thrust that is once per worker,');
        $this->output->writeln('  then the warm app serves every subsequent request.');
        $this->output->writeln('  Application::bootstrap()');
        $this->output->writeln('');

        $this->bootstrappers();
        $this->providers();

        $this->heading('PHASE 2 — REQUEST');
        $this->output->writeln('  Runs per request. Kernel::run()');
        $this->output->writeln('');
        $this->requestFlow();

        $this->deferred();
    }

    // ── Phase 1 ──────────────────────────────────────────────────────────

    private function bootstrappers(): void
    {
        $this->label('  Bootstrappers  (runBootstrappers, in order)');

        $bootstrappers = $this->app->getBootstrappers();
        if ($bootstrappers === []) {
            $this->output->writeln('    (none)');
        }
        foreach ($bootstrappers as $index => $class) {
            $this->output->writeln('    ' . ($index + 1) . '. ' . $this->shortName($class));
        }
        $this->output->writeln('');
    }

    private function providers(): void
    {
        $registered = $this->app->getServiceProviders();
        $bootable   = $this->app->getBootableProviders();

        // Identity comparison: the same instances land in both lists.
        $bootableHashes = [];
        foreach ($bootable as $provider) {
            $bootableHashes[spl_object_id($provider)] = true;
        }

        $this->label('  Providers  (' . count($registered) . ' registered, ' . count($bootable) . ' with boot())');
        $this->output->writeln('    register() runs in this order, then boot() runs in this order.');

        foreach ($registered as $index => $provider) {
            $boots = isset($bootableHashes[spl_object_id($provider)]);
            $mark  = $boots ? $this->output->color('boot', 'green') : '    ';
            $this->output->writeln(
                '    ' . str_pad((string) ($index + 1), 3, ' ', STR_PAD_LEFT) . '  '
                . $mark . '  ' . $this->shortName(get_class($provider))
            );
        }
        $this->output->writeln('');
    }

    // ── Phase 2 ──────────────────────────────────────────────────────────

    private function requestFlow(): void
    {
        $hooks = $this->kernel->getLifecycleHooks();

        $this->output->writeln('    Request::capture()');
        $this->output->writeln('      |');
        $this->hookStep('requestReceived', $hooks['requestReceived'] ?? []);
        $this->output->writeln('      |');
        $this->output->writeln('    ' . $this->output->color('sendRequestThroughRouter()', 'cyan', true));
        $this->output->writeln('      |   match route -> gather middleware -> dispatch handler');
        $this->middleware();
        $this->output->writeln('      |');
        $this->hookStep('responseReady', $hooks['responseReady'] ?? []);
        $this->output->writeln('      |');
        $this->output->writeln('    Response::send()');
        $this->output->writeln('      |');
        $this->hookStep('terminating', $hooks['terminating'] ?? [], 'after the response is sent');
        $this->output->writeln('');
    }

    private function middleware(): void
    {
        $global = $this->kernel->getMiddleware();
        $groups = $this->kernel->getMiddlewareGroups();

        $this->output->writeln('      |');
        $this->output->writeln('      |   global middleware (every route):');
        if ($global === []) {
            $this->output->writeln('      |     (none)');
        }
        foreach ($global as $class) {
            $this->output->writeln('      |     - ' . $this->shortName($class));
        }

        foreach ($groups as $name => $members) {
            $this->output->writeln('      |');
            $this->output->writeln('      |   group ' . $this->output->color($name, 'yellow', true)
                . '  (' . count($members) . ')');
            if ($members === []) {
                $this->output->writeln('      |     (empty — the lean path)');
                continue;
            }
            foreach ($members as $class) {
                $this->output->writeln('      |     - ' . $this->shortName($class));
            }
        }
    }

    /**
     * Print one hook seam plus where each attached callback was defined.
     *
     * The file:line comes from reflecting the closure itself, so it points at
     * the provider that attached it rather than at the kernel that runs it.
     *
     * @param array<int, callable> $hooks
     */
    private function hookStep(string $name, array $hooks, string $note = ''): void
    {
        $count = count($hooks);
        $line  = '    ' . $this->output->color($name . ' hooks', 'cyan', true) . '  (' . $count . ')';
        if ($note !== '') {
            $line .= '  — ' . $note;
        }
        $this->output->writeln($line);

        if ($count === 0) {
            $this->output->writeln('        (none attached)');
            return;
        }

        foreach ($hooks as $hook) {
            $this->output->writeln('        <- ' . $this->origin($hook));
        }
    }

    /** Best-effort "file:line" for wherever a hook callable was defined. */
    private function origin(callable $hook): string
    {
        if (! $hook instanceof Closure) {
            return is_string($hook) ? $hook : 'callable';
        }

        try {
            $reflection = new ReflectionFunction($hook);
            $file = $reflection->getFileName();
            $line = $reflection->getStartLine();

            if ($file === false) {
                return 'closure';
            }

            // Trim to a repo-relative path; the absolute one is just noise.
            $base = $this->app->paths()->base();
            if ($base !== '' && str_starts_with($file, $base)) {
                $file = ltrim(substr($file, strlen($base)), '\\/');
            } else {
                // A framework file resolved through vendor/ or a path symlink.
                $marker = 'src' . DIRECTORY_SEPARATOR;
                $at = strrpos($file, $marker);
                if ($at !== false) {
                    $file = substr($file, $at);
                }
            }

            return str_replace('\\', '/', $file) . ':' . $line;
        } catch (\ReflectionException) {
            return 'closure';
        }
    }

    // ── Deferred ─────────────────────────────────────────────────────────

    private function deferred(): void
    {
        $deferred = $this->app->getDeferredServices();
        if ($deferred === []) {
            return;
        }

        $this->heading('DEFERRED');
        $this->output->writeln('  Registered lazily — these have NOT run register() or boot() yet.');
        $this->output->writeln('  The container loads the provider on first resolve of the service.');
        $this->output->writeln('');

        $byProvider = [];
        foreach ($deferred as $service => $provider) {
            $byProvider[$provider][] = $service;
        }

        foreach ($byProvider as $provider => $services) {
            $this->output->writeln('    ' . $this->shortName($provider));
            foreach ($services as $service) {
                $this->output->writeln('      provides ' . $this->shortName($service));
            }
        }
        $this->output->writeln('');
    }

    // ── Formatting ───────────────────────────────────────────────────────

    private function heading(string $text): void
    {
        $this->output->writeln('');
        $this->output->writeln($this->output->color($text, 'magenta', true));
        $this->output->writeln($this->output->color(str_repeat('=', max(strlen($text), 20)), 'magenta'));
    }

    private function label(string $text): void
    {
        $this->output->writeln($this->output->color($text, 'blue', true));
    }

    /** Class short name, keeping the tail namespace segment for context. */
    private function shortName(string $class): string
    {
        if (! str_contains($class, '\\')) {
            return $class;
        }

        return substr(strrchr($class, '\\'), 1);
    }
}
