<?php

namespace Nitro\Mail\Transports;

use Nitro\Mail\Contracts\Transport;
use Nitro\Mail\Message;

/** Collects messages in memory for assertions in tests. */
class ArrayTransport implements Transport
{
    /** @var array<int, Message> */
    public array $messages = [];

    public function send(Message $message): void
    {
        $this->messages[] = $message;
    }

    public function flush(): void
    {
        $this->messages = [];
    }
}
