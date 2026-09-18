<?php

namespace Nitro\Redis;

use Redis;
use RuntimeException;

/**
 * Builds a phpredis client from a connection config array.
 *
 * Single place where connection options are interpreted, so the cache, session,
 * and queue drivers all understand the same keys — notably `scheme` and
 * `username`, which managed Redis providers require and which are easy to
 * support in one driver and forget in the next.
 *
 * Recognised keys: scheme, host, port, timeout, persistent, username, password,
 * database, prefix, read_timeout.
 */
class Connector
{
    /**
     * @param array $config
     * @return Redis
     */
    public static function connect(array $config): Redis
    {
        if (! extension_loaded('redis')) {
            throw new RuntimeException(
                'The phpredis extension is required for Redis connections. '
                    . 'Install it via: pecl install redis'
            );
        }

        $client = new Redis();

        $connect = ($config['persistent'] ?? false) ? 'pconnect' : 'connect';

        $client->{$connect}(
            static::host($config),
            (int) ($config['port'] ?? 6379),
            (float) ($config['timeout'] ?? 0.0),
        );

        static::authenticate($client, $config);

        if (isset($config['database'])) {
            $client->select((int) $config['database']);
        }

        if (isset($config['read_timeout'])) {
            $client->setOption(Redis::OPT_READ_TIMEOUT, (string) $config['read_timeout']);
        }

        if (! empty($config['prefix'])) {
            $client->setOption(Redis::OPT_PREFIX, (string) $config['prefix']);
        }

        return $client;
    }

    /**
     * The host to dial, carrying the scheme when the connection is encrypted.
     *
     * phpredis has no scheme argument: a TLS connection is requested by
     * prefixing the host with `tls://`. A host that already carries a scheme is
     * left alone.
     */
    protected static function host(array $config): string
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');

        if (str_contains($host, '://')) {
            return $host;
        }

        $scheme = strtolower((string) ($config['scheme'] ?? 'tcp'));

        return in_array($scheme, ['tls', 'ssl', 'rediss'], true) ? 'tls://' . $host : $host;
    }

    /**
     * Authenticate when credentials are configured.
     *
     * A username means ACL authentication and must be sent as a two-element
     * array; sending the password alone against an ACL-enabled server
     * authenticates as `default` and fails.
     */
    protected static function authenticate(Redis $client, array $config): void
    {
        $password = $config['password'] ?? null;

        if ($password === null || $password === '') {
            return;
        }

        $username = $config['username'] ?? null;

        if ($username === null || $username === '') {
            $client->auth((string) $password);

            return;
        }

        $client->auth([(string) $username, (string) $password]);
    }
}
