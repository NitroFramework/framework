<?php

namespace Nitro\Broadcasting;

/**
 * Lets a broadcast skip the connection that caused it.
 *
 *     broadcast(new MessageSent($message))->toOthers();
 *
 * The person who sent a message already has it on screen — their own client
 * put it there before the request returned. Without this they receive it a
 * second time over the socket and it appears twice, which is the single most
 * common broadcasting complaint and is invisible until two browsers are open.
 *
 * The socket id comes from the client, on the X-Socket-Id header, and the
 * driver excludes that connection when it is set.
 */
trait InteractsWithSockets
{
    /** The connection that should not receive this broadcast. */
    public ?string $socket = null;

    /** Exclude the connection this request came from. */
    public function dontBroadcastToCurrentUser(): static
    {
        $this->socket = static::currentSocketId();

        return $this;
    }

    /** Laravel's name for {@see dontBroadcastToCurrentUser()}. */
    public function broadcastToEveryone(): static
    {
        $this->socket = null;

        return $this;
    }

    /** The socket id this request carried, if any. */
    public static function currentSocketId(): ?string
    {
        $container = \Nitro\Container\Container::hasInstance()
            ? \Nitro\Container\Container::getInstance()
            : null;

        if ($container === null || ! $container->has('request')) {
            return null;
        }

        $id = $container->resolve('request')->header('X-Socket-Id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
