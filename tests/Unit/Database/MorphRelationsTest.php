<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use PHPUnit\Framework\TestCase;

/**
 * Polymorphic relations: a row that points at one of several kinds of parent.
 *
 * The case this is really for is an audit or log table — a sent email that is
 * about an enrolment, or a certificate, or an order — where a foreign key per
 * kind would mean a new nullable column every time the product grows.
 */
class MorphRelationsTest extends TestCase
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
        $g->setValue(null, new \Nitro\Database\Query\Grammar\SqliteGrammar());

        $conn->statement('CREATE TABLE morph_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, reference TEXT)');
        $conn->statement('CREATE TABLE morph_certificates (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT)');
        $conn->statement('CREATE TABLE morph_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, subject TEXT, related_id INTEGER, related_type TEXT)');

        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
        DB::disconnect();
        parent::tearDown();
    }

    private function email(string $subject, string $type, int $id): void
    {
        DB::table('morph_emails')->insert([
            'subject' => $subject,
            'related_type' => $type,
            'related_id' => $id,
        ]);
    }

    public function test_morph_to_resolves_the_right_class(): void
    {
        $orderId = (int) DB::table('morph_orders')->insertGetId(['reference' => 'LP-2026-0042']);
        $certId = (int) DB::table('morph_certificates')->insertGetId(['code' => 'ABC-123']);

        $this->email('Your receipt', MorphOrder::class, $orderId);
        $this->email('Your certificate', MorphCertificate::class, $certId);

        $emails = MorphEmail::query()->get()->all();

        $this->assertInstanceOf(MorphOrder::class, $emails[0]->relatedTo()->first());
        $this->assertSame('LP-2026-0042', $emails[0]->relatedTo()->first()->reference);

        $this->assertInstanceOf(MorphCertificate::class, $emails[1]->relatedTo()->first());
        $this->assertSame('ABC-123', $emails[1]->relatedTo()->first()->code);
    }

    public function test_morph_to_infers_its_columns_from_the_method_name(): void
    {
        // related() with no argument reads related_id / related_type. The name
        // is taken verbatim, so a method called relatedTo() would look for
        // relatedTo_id — name the method after the columns, or pass the name.
        $orderId = (int) DB::table('morph_orders')->insertGetId(['reference' => 'LP-1']);
        $this->email('Receipt', MorphOrder::class, $orderId);

        $relation = MorphEmail::query()->first()->related();

        $this->assertSame('related_id', $relation->getForeignKey());
        $this->assertSame('related_type', $relation->getTypeColumn());
        $this->assertSame('LP-1', $relation->first()->reference);
    }

    public function test_a_row_pointing_at_a_class_that_no_longer_exists_is_null(): void
    {
        // Old rows outlive refactors. A deleted class must not take down a list
        // screen that merely mentions it.
        $this->email('Orphan', 'App\\Models\\LongGone', 7);

        $this->assertNull(MorphEmail::query()->first()->relatedTo()->first());
    }

    public function test_eager_loading_groups_by_type(): void
    {
        $orderId = (int) DB::table('morph_orders')->insertGetId(['reference' => 'LP-2026-0042']);
        $certId = (int) DB::table('morph_certificates')->insertGetId(['code' => 'ABC-123']);

        $this->email('Receipt', MorphOrder::class, $orderId);
        $this->email('Certificate', MorphCertificate::class, $certId);
        $this->email('Orphan', 'App\\Models\\LongGone', 9);

        $emails = MorphEmail::with('relatedTo')->get()->all();

        $this->assertInstanceOf(MorphOrder::class, $emails[0]->relatedTo);
        $this->assertInstanceOf(MorphCertificate::class, $emails[1]->relatedTo);
        $this->assertNull($emails[2]->relatedTo);
    }

    public function test_the_morph_map_keeps_class_names_out_of_the_rows(): void
    {
        Model::enforceMorphMap(['order' => MorphOrder::class]);

        try {
            $this->assertSame('order', (new MorphOrder)->getMorphClass());

            $orderId = (int) DB::table('morph_orders')->insertGetId(['reference' => 'LP-9']);
            $this->email('Receipt', 'order', $orderId);

            $this->assertInstanceOf(MorphOrder::class, MorphEmail::query()->first()->relatedTo()->first());
        } finally {
            (function () {
                static::$morphMap = [];
            })->bindTo(null, Model::class)();
        }
    }

    public function test_morph_many_only_returns_its_own_type(): void
    {
        $orderId = (int) DB::table('morph_orders')->insertGetId(['reference' => 'LP-1']);

        // Same id, different type — the type column is what keeps them apart.
        $this->email('Order email', MorphOrder::class, $orderId);
        $this->email('Certificate email', MorphCertificate::class, $orderId);

        $emails = MorphOrder::find($orderId)->emails()->get()->all();

        $this->assertCount(1, $emails);
        $this->assertSame('Order email', $emails[0]->subject);
    }
}

// ─── Fixtures ─────────────────────────────────────────────

class MorphOrder extends Model
{
    protected string $table = 'morph_orders';
    protected array $fillable = ['reference'];

    public function emails(): \Nitro\Database\Model\Relations\MorphMany
    {
        return $this->morphMany(MorphEmail::class, 'related');
    }
}

class MorphCertificate extends Model
{
    protected string $table = 'morph_certificates';
    protected array $fillable = ['code'];
}

class MorphEmail extends Model
{
    protected string $table = 'morph_emails';
    protected array $fillable = ['subject', 'related_id', 'related_type'];

    /** Named explicitly, because the method is not called related(). */
    public function relatedTo(): \Nitro\Database\Model\Relations\MorphTo
    {
        return $this->morphTo('related');
    }

    /** Named after its columns, so morphTo() can work them out itself. */
    public function related(): \Nitro\Database\Model\Relations\MorphTo
    {
        return $this->morphTo();
    }
}
