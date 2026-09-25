<?php

namespace Nitro\Tests\Fixtures\Package;

use Illuminate\Console\Command;

class AcmeCommand extends Command
{
    protected $signature = 'acme:hello {name=world}';

    protected $description = 'Package command';

    public function handle(AcmeGreeter $greeter): int
    {
        $this->line($greeter->greet($this->argument('name')));

        return self::SUCCESS;
    }
}
