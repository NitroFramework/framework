<?php

namespace Tests\Unit\Livewire;

use Nitro\Livewire\Component;
use Nitro\Livewire\Synthesizers\FloatSynth;
use Nitro\Livewire\Synthesizers\IntSynth;
use Nitro\Livewire\Synthesizers\SynthManager;
use PHPUnit\Framework\TestCase;

enum CoercionStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

/**
 * wire:model values arrive as strings, so an emptied numeric field sends "".
 * FloatSynth/IntSynth (ported from Livewire 4) turn that into null instead of a
 * real 0, and Component::coerce() applies them when setting a typed property.
 */
class WireModelCoercionTest extends TestCase
{
    // ── FloatSynth ───────────────────────────────────────────────────────

    public function test_float_synth_matches_only_float(): void
    {
        $s = new FloatSynth();
        $this->assertTrue($s->matchType('float'));
        $this->assertFalse($s->matchType('int'));
        $this->assertFalse($s->matchType('string'));
    }

    public function test_float_synth_empty_string_and_null_become_null(): void
    {
        $s = new FloatSynth();
        $this->assertNull($s->hydrateFromType('float', ''));
        $this->assertNull($s->hydrateFromType('float', null));
    }

    public function test_float_synth_casts_numeric_strings(): void
    {
        $s = new FloatSynth();
        $this->assertSame(3.5, $s->hydrateFromType('float', '3.5'));
        $this->assertSame(10.0, $s->hydrateFromType('float', '10'));
        $this->assertSame(0.0, $s->hydrateFromType('float', '0'));
    }

    public function test_float_synth_leaves_non_numeric_for_validation(): void
    {
        $this->assertSame('abc', (new FloatSynth())->hydrateFromType('float', 'abc'));
    }

    // ── IntSynth ─────────────────────────────────────────────────────────

    public function test_int_synth_empty_becomes_null_and_casts_whole_numbers(): void
    {
        $s = new IntSynth();
        $this->assertTrue($s->matchType('int'));
        $this->assertNull($s->hydrateFromType('int', ''));
        $this->assertNull($s->hydrateFromType('int', null));
        $this->assertSame(7, $s->hydrateFromType('int', '7'));
        // Non-integers are left as-is (validation's job), not silently truncated.
        $this->assertSame('3.5', $s->hydrateFromType('int', '3.5'));
    }

    public function test_synth_manager_resolves_type_synths(): void
    {
        $this->assertInstanceOf(FloatSynth::class, SynthManager::typeSynthFor('float'));
        $this->assertInstanceOf(IntSynth::class, SynthManager::typeSynthFor('int'));
        $this->assertNull(SynthManager::typeSynthFor('string'));
    }

    // ── Component::coerce integration ────────────────────────────────────

    private function component(): Component
    {
        return new class extends Component {
            public ?float $salary = null;
            public ?int $age = null;
            public float $ratio = 1.0;   // non-nullable
            public string $name = '';
            public bool $active = false;
            public ?CoercionStatus $status = null;
            public ?\DateTimeImmutable $startsAt = null;
        };
    }

    public function test_emptied_nullable_float_becomes_null_not_zero(): void
    {
        $c = $this->component();
        $c->setProperty('salary', '2500.50');
        $this->assertSame(2500.50, $c->salary);

        // The regression: an emptied field must clear to null, not 0.0.
        $c->setProperty('salary', '');
        $this->assertNull($c->salary);
    }

    public function test_emptied_nullable_int_becomes_null(): void
    {
        $c = $this->component();
        $c->setProperty('age', '42');
        $this->assertSame(42, $c->age);

        $c->setProperty('age', '');
        $this->assertNull($c->age);
    }

    public function test_non_numeric_on_typed_float_is_normalized_to_null(): void
    {
        $c = $this->component();
        $c->setProperty('salary', 'not-a-number');
        $this->assertNull($c->salary, 'non-numeric input must not TypeError the float property');
    }

    public function test_non_nullable_float_falls_back_to_zero_when_emptied(): void
    {
        $c = $this->component();
        $c->setProperty('ratio', '');
        $this->assertSame(0.0, $c->ratio, 'a non-nullable float can\'t hold null, so it falls back to 0.0');

        $c->setProperty('ratio', '2.5');
        $this->assertSame(2.5, $c->ratio);
    }

    public function test_string_and_bool_still_coerce(): void
    {
        $c = $this->component();
        $c->setProperty('name', 123);
        $this->assertSame('123', $c->name);

        $c->setProperty('active', '1');
        $this->assertTrue($c->active);
        $c->setProperty('active', '0');
        $this->assertFalse($c->active);
    }

    public function test_wire_model_coerces_a_string_into_an_enum_property(): void
    {
        $c = $this->component();

        // A <select> sends the backing value; it must become the enum case.
        $c->setProperty('status', 'active');
        $this->assertSame(CoercionStatus::Active, $c->status);

        // Emptied select → null (not a TypeError).
        $c->setProperty('status', '');
        $this->assertNull($c->status);

        // Invalid value → null (validation's job), not a crash.
        $c->setProperty('status', 'bogus');
        $this->assertNull($c->status);
    }

    public function test_wire_model_parses_a_date_string_into_a_datetime_property(): void
    {
        $c = $this->component();

        $c->setProperty('startsAt', '2025-03-14');
        $this->assertInstanceOf(\DateTimeImmutable::class, $c->startsAt);
        $this->assertSame('2025-03-14', $c->startsAt->format('Y-m-d'));

        $c->setProperty('startsAt', '');
        $this->assertNull($c->startsAt);

        $c->setProperty('startsAt', 'not-a-date');
        $this->assertNull($c->startsAt, 'unparseable input becomes null, not a TypeError');
    }
}
