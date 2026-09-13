<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\Contracts\CastsAttributes;
use Nitro\Database\Model\Model;
use Nitro\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Class casts (backed enums and CastsAttributes implementations) and the model
 * boot lifecycle.
 *
 * Both exist for the same reason: an application's invariants have to live on
 * the model, where a seeder and an import reach them, rather than only in the
 * screen that happens to write the row.
 */
class ModelCastsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }

        $conn = new class([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]) extends Connection {
            protected function buildDsn(array $c): string { return 'sqlite::memory:'; }
            protected function afterConnect(\PDO $pdo): void {}
        };

        $r = new \ReflectionClass(DB::class);
        $p = $r->getProperty('connection');
        $p->setAccessible(true);
        $p->setValue(null, $conn);
        $g = $r->getProperty('grammar');
        $g->setAccessible(true);
        $g->setValue(null, new \Nitro\Database\Query\Grammar\MySqlGrammar());

        $conn->statement('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT, blocks TEXT, total INTEGER, created_at TEXT, updated_at TEXT)');

        Model::setEventDispatcher(new Dispatcher());
        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        CastOrder::flushEventListeners();
        Model::clearBootedModels();
        Model::unsetEventDispatcher();
        DB::disconnect();
        parent::tearDown();
    }

    // ─── Backed enums ─────────────────────────────────────

    public function test_a_backed_enum_cast_returns_a_case(): void
    {
        CastOrder::create(['status' => OrderStatus::Paid, 'total' => 100]);

        $order = CastOrder::query()->first();

        $this->assertInstanceOf(OrderStatus::class, $order->status);
        $this->assertSame(OrderStatus::Paid, $order->status);
    }

    public function test_an_enum_is_stored_as_its_backing_value(): void
    {
        CastOrder::create(['status' => OrderStatus::Refunded, 'total' => 100]);

        $raw = DB::table('orders')->first();

        $this->assertSame('refunded', $raw->status);
    }

    public function test_an_enum_column_holding_an_unknown_case_reads_as_null(): void
    {
        DB::table('orders')->insert(['status' => 'gone', 'total' => 1]);

        // tryFrom, not from: a stale row must not throw on every read of the
        // model, or one bad row takes down every page that lists orders.
        $this->assertNull(CastOrder::query()->first()->status);
    }

    public function test_an_enum_survives_the_round_trip_through_to_array(): void
    {
        CastOrder::create(['status' => OrderStatus::Paid, 'total' => 100]);

        $array = CastOrder::query()->first()->toArray();

        $this->assertSame('paid', $array['status']);
    }

    // ─── Custom cast classes ──────────────────────────────

    public function test_a_custom_cast_class_hydrates_the_value_object(): void
    {
        CastOrder::create(['status' => OrderStatus::Paid, 'blocks' => new BlockBag(['a', 'b'])]);

        $order = CastOrder::query()->first();

        $this->assertInstanceOf(BlockBag::class, $order->blocks);
        $this->assertSame(['a', 'b'], $order->blocks->items);
    }

    public function test_a_custom_cast_writes_through_set(): void
    {
        CastOrder::create(['status' => OrderStatus::Paid, 'blocks' => new BlockBag(['x'])]);

        $this->assertSame('["x"]', DB::table('orders')->first()->blocks);
    }

    public function test_a_cast_class_is_resolved_once_and_reused(): void
    {
        CountingCast::$instances = 0;

        CastCounter::create(['status' => 'a', 'total' => 1]);
        CastCounter::create(['status' => 'b', 'total' => 2]);

        foreach (CastCounter::query()->get()->all() as $row) {
            $row->total;
        }

        $this->assertSame(1, CountingCast::$instances, 'a stateless caster should be built once per class');
    }

    // ─── Boot lifecycle ───────────────────────────────────

    public function test_booted_runs_once_per_class(): void
    {
        BootedOrder::$bootCount = 0;

        new BootedOrder();
        new BootedOrder();
        BootedOrder::query();

        $this->assertSame(1, BootedOrder::$bootCount);
    }

    public function test_a_guard_registered_in_booted_vetoes_a_save(): void
    {
        $order = CastOrder::create(['status' => OrderStatus::Paid, 'total' => 100]);

        // CastOrder::booted() refuses any update once the order is paid.
        $order->total = 999;
        $order->save();

        $this->assertSame(100, (int) DB::table('orders')->first()->total);
    }

    public function test_a_trait_boot_hook_runs_too(): void
    {
        BootedOrder::$tracked = false;

        new BootedOrder();

        $this->assertTrue(BootedOrder::$tracked, 'bootTracksBoot() should have run');
    }
}

// ─── Fixtures ─────────────────────────────────────────────

enum OrderStatus: string
{
    case Paid = 'paid';
    case Refunded = 'refunded';
}

final class BlockBag
{
    public function __construct(public array $items) {}
}

final class BlockBagCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return new BlockBag(json_decode($value, true) ?? []);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return json_encode($value instanceof BlockBag ? $value->items : $value);
    }
}

final class CountingCast implements CastsAttributes
{
    public static int $instances = 0;

    public function __construct()
    {
        self::$instances++;
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return (int) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}

trait TracksBoot
{
    protected static function bootTracksBoot(): void
    {
        static::$tracked = true;
    }
}

class CastOrder extends Model
{
    protected string $table = 'orders';
    protected array $fillable = ['status', 'blocks', 'total'];
    protected array $casts = [
        'status' => OrderStatus::class,
        'blocks' => BlockBagCast::class,
    ];

    protected static function booted(): void
    {
        static::updating(fn (CastOrder $order) => $order->status !== OrderStatus::Paid);
    }
}

class CastCounter extends Model
{
    protected string $table = 'orders';
    protected array $fillable = ['status', 'total'];
    protected array $casts = ['total' => CountingCast::class];
}

class BootedOrder extends Model
{
    use TracksBoot;

    protected string $table = 'orders';
    protected array $fillable = ['status', 'total'];

    public static int $bootCount = 0;
    public static bool $tracked = false;

    protected static function booted(): void
    {
        static::$bootCount++;
    }
}
