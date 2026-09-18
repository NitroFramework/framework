<?php

namespace Nitro\Facades;

/**
 * Response facade — builds responses.
 *
 *   Response::json(['ok' => true]);
 *   Response::view('orders.show', ['order' => $order]);
 *
 * @method static \Nitro\Http\Response make(string $content = '', int $status = 200, array $headers = [])
 * @method static \Nitro\Http\Response json(mixed $data = [], int $status = 200, array $headers = [])
 * @method static \Nitro\Http\Response view(string $view, array $data = [], int $status = 200)
 * @method static \Nitro\Http\Response noContent(int $status = 204)
 * @method static \Nitro\Http\Response stream(callable $callback, int $status = 200, array $headers = [])
 * @method static \Nitro\Http\Response download(string $path, ?string $name = null, array $headers = [])
 */
class Response extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'response';
    }
}
