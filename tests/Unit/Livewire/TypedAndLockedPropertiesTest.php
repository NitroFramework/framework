<?php

namespace Tests\Unit\Livewire;

use Nitro\Livewire\Attributes\Locked;
use Nitro\Livewire\Component;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Nitro requires typed public component properties (so wire:model coercion is
 * deterministic) and honours #[Locked] (so the browser can't mutate protected
 * properties like record IDs).
 */
class TypedAndLockedPropertiesTest extends TestCase
{
    public function test_fully_typed_component_passes_the_typed_assertion(): void
    {
        $c = new class extends Component {
            public string $name = '';
            public ?int $age = null;
            public array $tags = [];
        };

        $c->assertPropertiesAreTyped();
        $this->assertTrue(true); // no exception thrown
    }

    public function test_untyped_public_property_is_rejected(): void
    {
        $c = new class extends Component {
            public $oops = null; // no type — bad code
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no type');
        $c->assertPropertiesAreTyped();
    }

    public function test_static_and_typed_properties_do_not_trip_the_assertion(): void
    {
        $c = new class extends Component {
            public static $shared = 1; // static is exempt
            public int $count = 0;
        };

        $c->assertPropertiesAreTyped();
        $this->assertTrue(true);
    }

    public function test_locked_property_is_detected(): void
    {
        $c = new class extends Component {
            #[Locked]
            public int $studentId = 5;

            public string $note = '';
        };

        $this->assertTrue($c->isPropertyLocked('studentId'));
        $this->assertFalse($c->isPropertyLocked('note'));
    }

    public function test_locked_detection_resolves_nested_keys_to_their_root(): void
    {
        $c = new class extends Component {
            #[Locked]
            public array $meta = ['id' => 1];
        };

        // A nested wire:model="meta.id" still resolves to the locked root.
        $this->assertTrue($c->isPropertyLocked('meta.id'));
    }
}
