<?php

namespace Tests\Unit\Database;

use LogicException;
use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use Nitro\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * A model guard has to survive the application being rebuilt.
 *
 * booted() registers its listeners against whichever dispatcher was current
 * when it ran, but the flag recording that a class has booted is static and
 * outlives the application that set that dispatcher. Build a second
 * application in the same process — every test does, and so does a worker that
 * rebuilds the app — and the models stay marked booted while their listeners
 * sit on a dispatcher nothing fires any more.
 *
 * The failure is silent and it is the worst kind: an immutable record accepts
 * an update and reports success. DatabaseServiceProvider::register() therefore
 * clears the booted flags before it installs the dispatcher, and these tests
 * stand in for that sequence.
 */
class ModelGuardsAcrossApplicationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }

        $connection = new class(['driver' => 'sqlite', 'database' => ':memory:']) extends Connection {
            protected function buildDsn(array $c): string { return 'sqlite::memory:'; }
            protected function afterConnect(\PDO $pdo): void {}
        };

        $reflection = new \ReflectionClass(DB::class);

        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $property->setValue(null, $connection);

        $grammar = $reflection->getProperty('grammar');
        $grammar->setAccessible(true);
        $grammar->setValue(null, new \Nitro\Database\Query\Grammar\SqliteGrammar());

        $connection->statement('CREATE TABLE ledgers (id INTEGER PRIMARY KEY AUTOINCREMENT, amount INTEGER)');

        Model::clearBootedModels();
        Ledger::$boots = 0;
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
        Model::unsetEventDispatcher();
        DB::disconnect();

        parent::tearDown();
    }

    /**
     * What a new application does: clear the booted flags, then install its own
     * dispatcher. The order matters — clearing afterwards would leave the next
     * boot registering against a dispatcher that is about to be replaced.
     */
    private function bootApplication(): Dispatcher
    {
        Model::clearBootedModels();

        $dispatcher = new Dispatcher();
        Model::setEventDispatcher($dispatcher);

        return $dispatcher;
    }

    public function test_a_guard_holds_in_the_first_application(): void
    {
        $this->bootApplication();

        $ledger = Ledger::create(['amount' => 10]);

        $this->expectException(LogicException::class);

        $ledger->forceFill(['amount' => 20])->save();
    }

    public function test_a_guard_still_holds_after_the_application_is_rebuilt(): void
    {
        $this->bootApplication();

        Ledger::create(['amount' => 10]);

        // A second application in the same process. Without the clear, Ledger
        // is still marked booted, its listener belongs to the dispatcher that
        // has just been thrown away, and the write below goes through.
        $this->bootApplication();

        $ledger = Ledger::query()->first();

        $this->expectException(LogicException::class);

        $ledger->forceFill(['amount' => 20])->save();
    }

    public function test_the_rebuilt_application_fires_on_its_own_dispatcher(): void
    {
        $this->bootApplication();
        $second = $this->bootApplication();

        $seen = [];
        $second->listen('model.created: ' . Ledger::class, function (Ledger $ledger) use (&$seen): void {
            $seen[] = (int) $ledger->amount;
        });

        Ledger::create(['amount' => 7]);

        $this->assertSame([7], $seen);
    }

    public function test_a_listener_is_not_registered_twice_by_one_application(): void
    {
        $this->bootApplication();

        // Two instances, one boot. Registering per instance would fire every
        // guard twice per save, which is how a "once" check becomes a double
        // write somewhere else.
        new Ledger();
        new Ledger();

        $this->assertSame(1, Ledger::$boots);
    }
}

class Ledger extends Model
{
    protected string $table = 'ledgers';
    protected array $fillable = ['amount'];
    protected bool $timestamps = false;

    /** How many times booted() has run, for the double-registration check. */
    public static int $boots = 0;

    protected static function booted(): void
    {
        static::$boots++;

        static::updating(function (Ledger $ledger): bool {
            throw new LogicException('A ledger entry is a record, not state.');
        });
    }
}
