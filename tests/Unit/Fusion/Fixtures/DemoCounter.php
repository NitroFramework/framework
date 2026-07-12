<?php

namespace Tests\Unit\Fusion\Fixtures;

use Nitro\Fusion\Attributes\Client;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Concerns\Transpilable;

#[Client]
class DemoCounter
{
    use Transpilable;

    public int $count = 0;
    public int $persisted = 0;
    protected string $secret = 'server-only';

    public function increment(): void
    {
        $this->count++;
    }

    #[Server]
    public function persist(): void
    {
        // stands in for a DB write — just records the count server-side
        $this->persisted = $this->count;
    }

    public function secretValue(): string
    {
        return $this->secret;
    }
}
