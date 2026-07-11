<?php

namespace Tests\Unit\Livewire;

use Nitro\Database\Model\Model;
use Nitro\Livewire\SecurityPolicy;
use Nitro\Livewire\Synthesizers\EnumSynth;
use Nitro\Livewire\Synthesizers\ModelSynth;
use PHPUnit\Framework\TestCase;
use RuntimeException;

enum SynthSecurityColor: string
{
    case Red = 'red';
    case Blue = 'blue';
}

class SynthSecurityFakeModel extends Model
{
    protected string $table = 'synth_security_fakes';
}

/**
 * Defense-in-depth for the Livewire synthesizer class-string sinks.
 *
 * A component snapshot is HMAC-checksum-verified before hydration, but the
 * ModelSynth/EnumSynth `new $class` / `$class::from()` sinks must ALSO refuse
 * anything outside their declared base type and honour the SecurityPolicy
 * denylist — so a checksum bypass (e.g. a leaked APP_KEY) can't reach a gadget
 * class. Mirrors livewire/livewire's SecurityPolicy.
 */
class SynthSecurityPolicyTest extends TestCase
{
    public function test_model_synth_refuses_a_non_model_class(): void
    {
        $this->expectException(RuntimeException::class);
        (new ModelSynth())->hydrate(['x' => 1], ['class' => \stdClass::class]);
    }

    public function test_model_synth_hydrates_a_real_model(): void
    {
        $model = (new ModelSynth())->hydrate(
            ['name' => 'A'],
            ['class' => SynthSecurityFakeModel::class],
        );
        $this->assertInstanceOf(SynthSecurityFakeModel::class, $model);
    }

    public function test_enum_synth_refuses_a_non_enum_class(): void
    {
        $this->expectException(RuntimeException::class);
        (new EnumSynth())->hydrate('red', ['class' => \stdClass::class]);
    }

    public function test_enum_synth_hydrates_a_real_enum(): void
    {
        $case = (new EnumSynth())->hydrate('blue', ['class' => SynthSecurityColor::class]);
        $this->assertSame(SynthSecurityColor::Blue, $case);
    }

    public function test_security_policy_denies_dangerous_classes(): void
    {
        $this->expectException(RuntimeException::class);
        SecurityPolicy::validateClass(\Nitro\Queue\Job::class);
    }

    public function test_security_policy_allows_ordinary_classes(): void
    {
        SecurityPolicy::validateClass(\Nitro\Support\Collection::class);
        $this->assertContains('Nitro\\Queue\\Job', SecurityPolicy::deniedClasses());
    }
}
