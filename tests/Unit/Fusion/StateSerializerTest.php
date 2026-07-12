<?php

namespace Tests\Unit\Fusion;

use Nitro\Fusion\State\StateSerializer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

enum SsColor: string
{
    case Red = 'red';
    case Blue = 'blue';
}

class SsHost
{
    public SsColor $color = SsColor::Red;
    public int $n = 0;
    public array $tags = [];
}

/**
 * Fusion's own state (de)serialization — scalars/arrays pass through, backed
 * enums round-trip via their ->value + the prop's declared type. No Livewire.
 */
class StateSerializerTest extends TestCase
{
    public function test_scalars_and_arrays_pass_through(): void
    {
        $this->assertSame(5, StateSerializer::dehydrate(5));
        $this->assertSame('x', StateSerializer::dehydrate('x'));
        $this->assertNull(StateSerializer::dehydrate(null));
        $this->assertSame(['a', 1, true], StateSerializer::dehydrate(['a', 1, true]));
    }

    public function test_backed_enum_dehydrates_to_its_value(): void
    {
        $this->assertSame('red', StateSerializer::dehydrate(SsColor::Red));
        $this->assertSame(['blue', 1], StateSerializer::dehydrate([SsColor::Blue, 1]));
    }

    public function test_backed_enum_hydrates_from_value_via_prop_type(): void
    {
        $type = (new ReflectionProperty(SsHost::class, 'color'))->getType();

        $this->assertSame(SsColor::Blue, StateSerializer::hydrate('blue', $type));
        // already-hydrated enum is tolerated
        $this->assertSame(SsColor::Red, StateSerializer::hydrate(SsColor::Red, $type));
    }

    public function test_builtin_and_null_hydrate_as_is(): void
    {
        $intType = (new ReflectionProperty(SsHost::class, 'n'))->getType();
        $this->assertSame(7, StateSerializer::hydrate(7, $intType));
        $this->assertNull(StateSerializer::hydrate(null, null));
    }
}
