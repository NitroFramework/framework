<?php

namespace Nitro\Tests\Fixtures\Package;

class AcmeGreeter
{
    public function __construct(public string $greeting)
    {
    }

    public function greet(string $name): string
    {
        return "{$this->greeting}, {$name}";
    }
}
