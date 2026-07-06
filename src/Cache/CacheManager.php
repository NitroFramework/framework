<?php

namespace Nitro\Cache;

use Nitro\Cache\Contracts\StoreInterface;
use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\Drivers\FileStore;
use Nitro\Cache\Drivers\NullStore;
use Nitro\Cache\Drivers\RedisStore;

/**
 * Resolves and caches the configured cache store driver (array/file/redis/null).
 */
class CacheManager
{
    /**
     * Resolved cache store instances.
     *
     * @var array<string, Repository>
     */
    protected array $stores = [];

    /**
     * Registered custom driver creators.
     *
     * @var array<string, \Closure>
     */
    protected array $customCreators = [];

    /**
     * @param array $config The full cache configuration array
     */
    public function __construct(
        protected array $config = []
    ) {}

    // -------------------------------------------------------------------------
    // Store Resolution
    // -------------------------------------------------------------------------

    /**
     * Get a cache store instance by name.
     *
     * @param string|null $name  Store name from config, null = default
     * @return Repository
     */
    public function store(?string $name = null): Repository
    {
        $name = $name ?? $this->getDefaultDriver();

        if (! isset($this->stores[$name])) {
            $this->stores[$name] = $this->resolve($name);
        }

        return $this->stores[$name];
    }

    /**
     * Alias for store().
     *
     * @param string|null $name
     * @return Repository
     */
    public function driver(?string $name = null): Repository
    {
        return $this->store($name);
    }

    /**
     * Resolve a cache store by name.
     *
     * @param string $name
     * @return Repository
     * @throws \InvalidArgumentException
     */
    protected function resolve(string $name): Repository
    {
        $config = $this->getStoreConfig($name);

        if ($config === null) {
            throw new \InvalidArgumentException(
                "Cache store [{$name}] is not defined."
            );
        }

        $driver = $config['driver'] ?? $name;

        // Check custom creators first
        if (isset($this->customCreators[$driver])) {
            $store = ($this->customCreators[$driver])($config);

            return $this->repository($store, $config);
        }

        $method = 'create' . ucfirst($driver) . 'Driver';

        if (method_exists($this, $method)) {
            return $this->{$method}($config);
        }

        throw new \InvalidArgumentException(
            "Cache driver [{$driver}] is not supported."
        );
    }

    // -------------------------------------------------------------------------
    // Driver Factories
    // -------------------------------------------------------------------------

    /**
     * Create a file cache driver.
     *
     * @param array $config
     * @return Repository
     */
    protected function createFileDriver(array $config): Repository
    {
        $store = new FileStore(
            directory: $config['path'] ?? $this->config['path'] ?? storage_path('cache/data'),
            prefix: $config['prefix'] ?? $this->getPrefix(),
            allowedClasses: $config['allowed_classes'] ?? true,
        );

        return $this->repository($store, $config);
    }

    /**
     * Create a Redis cache driver.
     *
     * @param array $config
     * @return Repository
     */
    protected function createRedisDriver(array $config): Repository
    {
        if (! extension_loaded('redis')) {
            throw new \RuntimeException(
                'The phpredis extension is required to use the Redis cache driver. '
                    . 'Install it via: pecl install redis'
            );
        }

        /** @var \Redis $redis */
        $redis = new \Redis();

        $redis->connect(
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 6379,
            $config['timeout'] ?? 0.0
        );

        if (! empty($config['password'])) {
            $redis->auth($config['password']);
        }

        if (isset($config['database'])) {
            $redis->select((int) $config['database']);
        }

        $store = new RedisStore(
            redis: $redis,
            prefix: $config['prefix'] ?? $this->getPrefix(),
            allowedClasses: $config['allowed_classes'] ?? true,
        );

        return $this->repository($store, $config);
    }

    /**
     * Create an array (in-memory) cache driver.
     *
     * @param array $config
     * @return Repository
     */
    protected function createArrayDriver(array $config): Repository
    {
        $store = new ArrayStore(
            prefix: $config['prefix'] ?? $this->getPrefix(),
        );

        return $this->repository($store, $config);
    }

    /**
     * Create a null cache driver.
     *
     * @param array $config
     * @return Repository
     */
    protected function createNullDriver(array $config): Repository
    {
        return $this->repository(new NullStore(), $config);
    }

    // -------------------------------------------------------------------------
    // Repository Builder
    // -------------------------------------------------------------------------

    /**
     * Wrap a store in a Repository.
     *
     * @param StoreInterface $store
     * @param array          $config
     * @return Repository
     */
    protected function repository(StoreInterface $store, array $config = []): Repository
    {
        // No default-TTL knob: an omitted TTL means "forever" (Laravel/PSR-16),
        // and callers pass an explicit TTL when they want expiry.
        return new Repository($store);
    }

    // -------------------------------------------------------------------------
    // Custom Drivers
    // -------------------------------------------------------------------------

    /**
     * Register a custom driver creator.
     *
     * @param string   $driver
     * @param \Closure $callback
     * @return static
     */
    public function extend(string $driver, \Closure $callback): static
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Config Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the default driver name.
     *
     * @return string
     */
    public function getDefaultDriver(): string
    {
        return $this->config['default'] ?? 'file';
    }

    /**
     * Set the default driver name.
     *
     * @param string $name
     * @return void
     */
    public function setDefaultDriver(string $name): void
    {
        $this->config['default'] = $name;
    }

    /**
     * Get the configuration for a specific store.
     *
     * @param string $name
     * @return array|null
     */
    protected function getStoreConfig(string $name): ?array
    {
        return $this->config['stores'][$name] ?? null;
    }

    /**
     * Get the cache prefix from config.
     *
     * @return string
     */
    protected function getPrefix(): string
    {
        return $this->config['prefix'] ?? 'nitro_cache:';
    }

    /**
     * Forget a resolved store instance (useful for testing).
     *
     * @param string|null $name
     * @return static
     */
    public function forgetDriver(?string $name = null): static
    {
        $name = $name ?? $this->getDefaultDriver();

        unset($this->stores[$name]);

        return $this;
    }

    // -------------------------------------------------------------------------
    // Proxy to Default Store
    // -------------------------------------------------------------------------

    /**
     * Dynamically call the default store.
     *
     * @param string $method
     * @param array  $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->store()->$method(...$parameters);
    }
}
