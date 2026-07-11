<?php

namespace Tests\Unit\Livewire;

use Nitro\Livewire\Component;
use PHPUnit\Framework\TestCase;

/**
 * A browser update (wire:model / a forged `updates` entry) may only write
 * PUBLIC component properties. Protected/private state is server-only — letting
 * the client reach it could bypass invariants (a protected flag, cached rules,
 * a record id). Matches Livewire's public-property update guard.
 */
class PublicPropertyUpdateTest extends TestCase
{
    public function test_set_property_updates_a_public_property(): void
    {
        $c = new class extends Component {
            public string $name = '';
        };

        $c->setProperty('name', 'Alice');

        $this->assertSame('Alice', $c->name);
    }

    public function test_set_property_ignores_a_protected_property(): void
    {
        $c = new class extends Component {
            public string $name = '';
            protected string $secret = 'original';
            public function secret(): string { return $this->secret; }
        };

        $c->setProperty('secret', 'hacked');

        $this->assertSame('original', $c->secret());
    }

    public function test_set_property_ignores_a_private_property(): void
    {
        $c = new class extends Component {
            private string $token = 'original';
            public function token(): string { return $this->token; }
        };

        $c->setProperty('token', 'hacked');

        $this->assertSame('original', $c->token());
    }

    public function test_nested_update_ignores_a_protected_array_property(): void
    {
        $c = new class extends Component {
            protected array $form = ['role' => 'user'];
            public function form(): array { return $this->form; }
        };

        $c->setProperty('form.role', 'admin');

        $this->assertSame(['role' => 'user'], $c->form());
    }
}
