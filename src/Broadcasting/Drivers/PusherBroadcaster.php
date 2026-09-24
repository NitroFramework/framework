<?php

namespace Nitro\Broadcasting\Drivers;

use Nitro\Broadcasting\BroadcastException;
use Nitro\Broadcasting\Contracts\Broadcaster;
use Nitro\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Sends events to Pusher, or anything that speaks its protocol.
 *
 * Speaks the HTTP API directly over the framework's own client and signs each
 * call itself, so the driver adds no dependency — the same approach
 * {@see \Nitro\Filesystem\S3Filesystem} takes to S3. Soketi and Pusher-
 * compatible servers work by pointing `host` at them.
 *
 * Signing is Pusher's own scheme: an MD5 of the body, then an HMAC-SHA256 over
 * the request line, both as query parameters. Nothing here is a secret in
 * transit beyond the signature itself.
 *
 * Config keys:
 *   key, secret, app_id    Credentials from the dashboard.
 *   cluster                Shorthand for the hosted host, e.g. 'eu'.
 *   host, port, scheme     For a self-hosted server instead.
 */
class PusherBroadcaster implements Broadcaster
{
    protected string $key;

    protected string $secret;

    protected string $appId;

    protected string $host;

    /** @param array<string, mixed> $config */
    public function __construct(
        array $config,
        protected ?HttpFactory $http = null,
    ) {
        foreach (['key', 'secret', 'app_id'] as $required) {
            if (empty($config[$required])) {
                throw new BroadcastException("The pusher driver is missing its [{$required}] configuration value.");
            }
        }

        $this->key = (string) $config['key'];
        $this->secret = (string) $config['secret'];
        $this->appId = (string) $config['app_id'];

        $this->host = $this->hostFrom($config);
        $this->http ??= new HttpFactory();
    }

    /**
     * Where the API lives.
     *
     * A cluster names the hosted service; a host names your own server, which
     * is how a Pusher-compatible one is used.
     *
     * @param array<string, mixed> $config
     */
    protected function hostFrom(array $config): string
    {
        if (! empty($config['host'])) {
            $scheme = (string) ($config['scheme'] ?? 'https');
            $port = isset($config['port']) ? ':' . (int) $config['port'] : '';

            return $scheme . '://' . rtrim((string) $config['host'], '/') . $port;
        }

        $cluster = (string) ($config['cluster'] ?? 'mt1');

        return 'https://api-' . $cluster . '.pusher.com';
    }

    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        if ($channels === []) {
            return;
        }

        $socket = $payload['socket'] ?? null;

        unset($payload['socket']);

        $body = [
            'name' => $event,
            'channels' => array_values($channels),
            'data' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        // The connection that caused the event, so the sender does not get
        // their own message back and see it twice.
        if (is_string($socket) && $socket !== '') {
            $body['socket_id'] = $socket;
        }

        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $path = '/apps/' . $this->appId . '/events';

        try {
            $response = $this->http
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post(
                    $this->host . $path . '?' . $this->signature('POST', $path, (string) $encoded),
                    (string) $encoded,
                );
        } catch (Throwable $exception) {
            throw new BroadcastException(
                'Pusher could not be reached: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            throw new BroadcastException(
                'Pusher refused the broadcast with status ' . $response->status() . ': ' . $response->body()
            );
        }
    }

    /**
     * Pusher's query-string signature.
     *
     * The body is hashed, then the whole request line is signed, so neither
     * the payload nor the parameters can be altered in flight without the
     * signature failing.
     */
    protected function signature(string $method, string $path, string $body): string
    {
        $parameters = [
            'auth_key' => $this->key,
            'auth_timestamp' => (string) time(),
            'auth_version' => '1.0',
            'body_md5' => md5($body),
        ];

        // Signed in sorted order, which is what the other end reconstructs.
        ksort($parameters);

        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        $signature = hash_hmac(
            'sha256',
            $method . "\n" . $path . "\n" . $query,
            $this->secret,
        );

        return $query . '&auth_signature=' . $signature;
    }

    /**
     * The response a client needs to join a private or presence channel.
     *
     * The client sends its socket id and the channel; this signs the pair so
     * the socket server will admit it. Presence channels also carry the
     * member's identity, which is what makes a "who is here" list possible.
     *
     * @param array<string, mixed>|null $userData
     * @return array<string, string>
     */
    public function authFor(string $socketId, string $channel, ?array $userData = null): array
    {
        $signed = $socketId . ':' . $channel;

        if ($userData !== null) {
            $encoded = (string) json_encode($userData, JSON_UNESCAPED_SLASHES);

            $signed .= ':' . $encoded;

            return [
                'auth' => $this->key . ':' . hash_hmac('sha256', $signed, $this->secret),
                'channel_data' => $encoded,
            ];
        }

        return ['auth' => $this->key . ':' . hash_hmac('sha256', $signed, $this->secret)];
    }
}
