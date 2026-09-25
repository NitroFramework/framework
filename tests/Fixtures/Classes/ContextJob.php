<?php

namespace Nitro\Tests\Fixtures\Classes;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Context;

/**
 * A queued job that records the context it runs with and the context its payload carried.
 */
class ContextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var array<string, mixed>|null */
    public static ?array $seen = null;

    public static mixed $payloadContext = null;

    public function handle(): void
    {
        static::$seen = Context::all();
        static::$payloadContext = $this->job->payload()['illuminate:log:context'] ?? null;
    }
}
