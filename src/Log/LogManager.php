<?php

namespace Nitro\Log;

use Closure;
use InvalidArgumentException;
use Nitro\Log\Handlers\DailyHandler;
use Nitro\Log\Handlers\ErrorLogHandler;
use Nitro\Log\Handlers\Handler;
use Nitro\Log\Handlers\NullHandler;
use Nitro\Log\Handlers\StackHandler;
use Nitro\Log\Handlers\StreamHandler;

/**
 * Resolves configured log channels and memoizes them.
 *
 * A channel names a driver and its options; `stack` composes several channels
 * into one. Calls made on the manager itself go to the default channel, which
 * is what lets `Log::info(...)` work without naming one.
 *
 * A channel that is not configured raises rather than falling back, because a
 * log quietly written somewhere nobody reads is worse than a loud failure.
 */
class LogManager
{
    /**
     * Channels already built, by name.
     *
     * @var array<string, Logger>
     */
    protected array $channels = [];

    /**
     * Driver creators registered at runtime.
     *
     * @var array<string, Closure>
     */
    protected array $customCreators = [];

    /**
     * @param array $config The `logging` config: default, channels.
     */
    public function __construct(
        protected array $config,
    ) {}

    /**
     * Get a channel by name, or the default one.
     */
    public function channel(?string $name = null): Logger
    {
        return $this->driver($name);
    }

    /**
     * Get a channel by name, or the default one.
     */
    public function driver(?string $name = null): Logger
    {
        $name ??= $this->getDefaultDriver();

        return $this->channels[$name] ??= $this->resolve($name);
    }

    /**
     * Build a temporary stack from the given channel names.
     *
     * @param array<int, string> $channels
     */
    public function stack(array $channels, ?string $channel = null): Logger
    {
        $handlers = array_map(fn (string $name): Handler => $this->driver($name)->getHandler(), $channels);

        return new Logger(new StackHandler($handlers), 'debug');
    }

    /**
     * Build a channel from an inline configuration, without registering it.
     */
    public function build(array $config): Logger
    {
        return $this->build_($config, 'ondemand');
    }

    /**
     * Resolve a configured channel.
     *
     * @throws InvalidArgumentException When the channel is not configured.
     */
    protected function resolve(string $name): Logger
    {
        $config = $this->configurationFor($name);

        if ($config === null) {
            throw new InvalidArgumentException(
                "Log [{$name}] is not defined. Configured channels: "
                    . (implode(', ', array_keys($this->config['channels'] ?? [])) ?: 'none') . '.'
            );
        }

        return $this->build_($config, $name);
    }

    /**
     * Build a channel from its configuration.
     *
     * @throws InvalidArgumentException When the driver is unknown.
     */
    protected function build_(array $config, string $name): Logger
    {
        $driver = $config['driver'] ?? null;

        if ($driver === null) {
            throw new InvalidArgumentException("Log channel [{$name}] names no driver.");
        }

        if (isset($this->customCreators[$driver])) {
            $handler = ($this->customCreators[$driver])($config);
        } else {
            $handler = match ($driver) {
                'single'   => $this->createSingleDriver($config),
                'daily'    => $this->createDailyDriver($config),
                'stream'   => $this->createStreamDriver($config),
                'errorlog' => new ErrorLogHandler(),
                'null'     => new NullHandler(),
                'stack'    => $this->createStackDriver($config, $name),
                default    => throw new InvalidArgumentException(
                    "Log driver [{$driver}] is not supported (channel [{$name}])."
                ),
            };
        }

        return new Logger($handler, (string) ($config['level'] ?? 'debug'));
    }

    /** One file, rotated by size. */
    protected function createSingleDriver(array $config): Handler
    {
        return new StreamHandler(
            $this->requirePath($config, 'single'),
            (int) ($config['max_bytes'] ?? 0),
        );
    }

    /** One file per day, pruned to a retention window. */
    protected function createDailyDriver(array $config): Handler
    {
        return new DailyHandler(
            $this->requirePath($config, 'daily'),
            (int) ($config['days'] ?? 14),
        );
    }

    /** A PHP stream, such as php://stderr. */
    protected function createStreamDriver(array $config): Handler
    {
        return new StreamHandler((string) ($config['stream'] ?? 'php://stderr'));
    }

    /**
     * Several channels written to as one.
     *
     * @throws InvalidArgumentException When a stack names itself, directly or otherwise.
     */
    protected function createStackDriver(array $config, string $name): Handler
    {
        $names = (array) ($config['channels'] ?? []);

        if (in_array($name, $names, true)) {
            throw new InvalidArgumentException("Log stack [{$name}] contains itself.");
        }

        $handlers = array_map(fn (string $child): Handler => $this->driver($child)->getHandler(), $names);

        return new StackHandler($handlers, (bool) ($config['ignore_exceptions'] ?? false));
    }

    /**
     * @throws InvalidArgumentException When a file-backed channel names no path.
     */
    protected function requirePath(array $config, string $driver): string
    {
        $path = $config['path'] ?? null;

        if (! is_string($path) || $path === '') {
            throw new InvalidArgumentException("The {$driver} log driver needs a path.");
        }

        return $path;
    }

    /**
     * Get a channel's configuration, or null when it has none.
     */
    protected function configurationFor(string $name): ?array
    {
        $config = $this->config['channels'][$name] ?? null;

        return is_array($config) ? $config : null;
    }

    /**
     * Register a driver creator.
     *
     * @param Closure $callback Callable(array $config): Handler
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Discard a resolved channel so the next call rebuilds it.
     */
    public function forgetChannel(?string $name = null): void
    {
        unset($this->channels[$name ?? $this->getDefaultDriver()]);
    }

    /**
     * Get the channels resolved so far.
     *
     * @return array<string, Logger>
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * Get the name of the default channel.
     */
    public function getDefaultDriver(): string
    {
        return (string) ($this->config['default'] ?? 'stack');
    }

    /**
     * Set the default channel.
     */
    public function setDefaultDriver(string $name): void
    {
        $this->config['default'] = $name;
    }

    /**
     * Send anything else to the default channel.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->driver()->{$method}(...$arguments);
    }
}
