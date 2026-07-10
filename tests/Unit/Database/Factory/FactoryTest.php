<?php

namespace Tests\Unit\Database\Factory;

use Nitro\Database\Factory\Factory;
use Nitro\Database\Factory\Generator;
use Nitro\Database\Factory\UniqueGenerator;
use Nitro\Database\Model\Model;
use PHPUnit\Framework\TestCase;

/**
 * Factory + Generator + UniqueGenerator tests. Uses make() exclusively
 * — no DB roundtrip — so the suite stays self-contained. create() is
 * just makeOne() + Model::create(), which is exercised through normal
 * model usage elsewhere.
 */
class FactoryTest extends TestCase
{
    // ── Factory ──────────────────────────────────────────────────────

    public function test_make_returns_a_single_instance_when_count_is_one(): void
    {
        $instance = FactoryTestUserFactory::new()->make();

        $this->assertInstanceOf(FactoryTestUser::class, $instance);
        $this->assertNotEmpty($instance->name);
    }

    public function test_make_returns_an_array_when_count_above_one(): void
    {
        $instances = FactoryTestUserFactory::new()->count(3)->make();

        $this->assertIsArray($instances);
        $this->assertCount(3, $instances);
        foreach ($instances as $i) {
            $this->assertInstanceOf(FactoryTestUser::class, $i);
        }
    }

    public function test_overrides_win_over_definition(): void
    {
        $instance = FactoryTestUserFactory::new()->make(['name' => 'OVERRIDE']);
        $this->assertSame('OVERRIDE', $instance->name);
    }

    public function test_state_layers_over_definition(): void
    {
        $instance = FactoryTestUserFactory::new()
            ->state(['status' => 'banned'])
            ->make();

        $this->assertSame('banned', $instance->status);
    }

    public function test_named_state_helper_compoes_correctly(): void
    {
        $instance = FactoryTestUserFactory::new()->admin()->make();
        $this->assertTrue($instance->is_admin);
    }

    public function test_state_callable_receives_merged_attributes(): void
    {
        $instance = FactoryTestUserFactory::new()
            ->state(fn (array $attrs) => ['slug' => 'name-' . strlen($attrs['name'])])
            ->make();

        $this->assertSame('name-' . strlen($instance->name), $instance->slug);
    }

    public function test_count_returns_a_clone_so_chains_are_independent(): void
    {
        $a = FactoryTestUserFactory::new();
        $b = $a->count(3);
        $this->assertNotSame($a, $b, 'count() must return a clone');

        $this->assertCount(3, $b->make());
        $this->assertInstanceOf(FactoryTestUser::class, $a->make(),
            'original factory is unmodified — still count=1');
    }

    public function test_raw_returns_attribute_arrays_without_instantiating(): void
    {
        $row = FactoryTestUserFactory::new()->raw();
        $this->assertIsArray($row);
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayNotHasKey('id', $row);
    }

    public function test_missing_model_property_throws(): void
    {
        $factory = new class extends Factory {
            public function definition(): array { return []; }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must declare a $model property');
        $factory->make();
    }

    // ── Generator ────────────────────────────────────────────────────

    public function test_generator_produces_a_name(): void
    {
        $g = new Generator();
        $this->assertMatchesRegularExpression('/^\w+ \w+$/', $g->name());
    }

    public function test_generator_email_looks_like_an_email(): void
    {
        $g = new Generator();
        $this->assertMatchesRegularExpression('/@/', $g->email());
    }

    public function test_number_between_respects_bounds(): void
    {
        $g = new Generator();
        for ($i = 0; $i < 50; $i++) {
            $n = $g->numberBetween(5, 10);
            $this->assertGreaterThanOrEqual(5, $n);
            $this->assertLessThanOrEqual(10, $n);
        }
    }

    public function test_random_element_returns_a_member_of_the_array(): void
    {
        $g = new Generator();
        $pool = ['draft', 'published', 'archived'];
        for ($i = 0; $i < 20; $i++) {
            $this->assertContains($g->randomElement($pool), $pool);
        }
    }

    public function test_boolean_zero_percent_is_always_false(): void
    {
        $g = new Generator();
        for ($i = 0; $i < 20; $i++) {
            $this->assertFalse($g->boolean(0));
        }
    }

    public function test_uuid_is_v4_shaped(): void
    {
        $g = new Generator();
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
            $g->uuid()
        );
    }

    // ── UniqueGenerator ──────────────────────────────────────────────

    public function test_unique_never_repeats_within_one_scope(): void
    {
        $unique = new UniqueGenerator(new Generator());
        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $seen[] = $unique->uuid();
        }
        $this->assertSame(count($seen), count(array_unique($seen)),
            'unique()->uuid() must produce 20 distinct values');
    }

    public function test_unique_throws_when_pool_is_exhausted(): void
    {
        $unique = new UniqueGenerator(new class extends Generator {
            // Force the generator to a 2-value pool so unique() can only
            // succeed twice before hitting the retry cap.
            public function randomElement(array $values): mixed { return 'a'; }
        });

        $unique->randomElement(['a']); // first call seeds 'a' as seen

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not produce a unique value');
        $unique->randomElement(['a']); // every retry returns 'a' again
    }
}

/**
 * Minimal stand-in for a real Model — avoids touching the DB layer.
 * The factory only calls new $class() + fill(), which the parent already
 * provides via HasAttributes.
 */
class FactoryTestUser extends Model
{
    protected string $table = 'factory_test_users';
    protected array $fillable = ['name', 'email', 'status', 'is_admin', 'slug'];
}

class FactoryTestUserFactory extends Factory
{
    protected string $model = FactoryTestUser::class;

    public static function new(): self
    {
        return new self();
    }

    public function definition(): array
    {
        return [
            'name'     => $this->faker->name(),
            'email'    => $this->faker->unique()->email(),
            'status'   => 'active',
            'is_admin' => false,
            'slug'     => 'default-slug',
        ];
    }

    public function admin(): self
    {
        return $this->state(['is_admin' => true]);
    }
}
