<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use Nitro\Database\Model\ModelBuilder;
use Nitro\Database\Model\Scope;
use Nitro\Database\Model\Scopes\SoftDeletingScope;
use Nitro\Database\Model\SoftDeletes;
use PHPUnit\Framework\TestCase;

/**
 * Global scopes: registration, application to every query, and opting out.
 */
class GlobalScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }

        $connection = new class(['driver' => 'sqlite', 'database' => ':memory:']) extends Connection {
            protected function buildDsn(array $config): string
            {
                return 'sqlite::memory:';
            }

            protected function afterConnect(\PDO $pdo): void
            {
            }
        };

        $reflection = new \ReflectionClass(DB::class);

        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $property->setValue(null, $connection);

        $grammar = $reflection->getProperty('grammar');
        $grammar->setAccessible(true);
        $grammar->setValue(null, new \Nitro\Database\Query\Grammar\SqliteGrammar());

        $connection->statement(
            'CREATE TABLE articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                published_at TEXT NULL,
                deleted_at TEXT NULL
            )'
        );

        foreach ([
            ['Live and published', '2026-01-01', null],
            ['Live draft', null, null],
            ['Trashed published', '2026-01-01', '2026-02-01'],
        ] as [$title, $published, $deleted]) {
            $connection->insert(
                'INSERT INTO articles (title, published_at, deleted_at) VALUES (?, ?, ?)',
                [$title, $published, $deleted]
            );
        }

        $this->forgetScopes();
    }

    protected function tearDown(): void
    {
        $this->forgetScopes();
        DB::disconnect();
        parent::tearDown();
    }

    /**
     * Clear every registered scope and let each model boot again.
     *
     * Booting is once per class, so a model whose scope was registered by
     * bootSoftDeletes() would otherwise never register it a second time.
     */
    private function forgetScopes(): void
    {
        $model = new \ReflectionClass(Model::class);

        foreach (['globalScopes', 'booted'] as $name) {
            $property = $model->getProperty($name);
            $property->setAccessible(true);
            $property->setValue(null, []);
        }
    }

    // ─── Registration ─────────────────────────────────────

    public function test_a_scope_instance_is_keyed_by_its_class(): void
    {
        ScopedArticle::addGlobalScope(new PublishedScope());

        $this->assertTrue(ScopedArticle::hasGlobalScope(PublishedScope::class));
        $this->assertTrue(ScopedArticle::hasGlobalScope(new PublishedScope()));
        $this->assertArrayHasKey(PublishedScope::class, ScopedArticle::getGlobalScopes());
    }

    public function test_a_closure_scope_is_keyed_by_the_given_identifier(): void
    {
        ScopedArticle::addGlobalScope('published', function (ModelBuilder $builder, Model $model): void {
            $builder->whereNotNull('published_at');
        });

        $this->assertTrue(ScopedArticle::hasGlobalScope('published'));
        $this->assertFalse(ScopedArticle::hasGlobalScope('nope'));
    }

    public function test_a_closure_without_an_identifier_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScopedArticle::addGlobalScope('orphan');
    }

    public function test_scopes_are_registered_per_model(): void
    {
        ScopedArticle::addGlobalScope(new PublishedScope());

        $this->assertTrue(ScopedArticle::hasGlobalScope(PublishedScope::class));
        $this->assertFalse(TrashableArticle::hasGlobalScope(PublishedScope::class));
    }

    // ─── Application ──────────────────────────────────────

    public function test_a_scope_constrains_every_query(): void
    {
        $this->assertSame(3, ScopedArticle::query()->count());

        ScopedArticle::addGlobalScope(new PublishedScope());

        $this->assertSame(2, ScopedArticle::query()->count());
    }

    public function test_a_closure_scope_constrains_every_query(): void
    {
        ScopedArticle::addGlobalScope('published', function (ModelBuilder $builder): void {
            $builder->whereNotNull('published_at');
        });

        $this->assertSame(2, ScopedArticle::query()->count());
    }

    public function test_several_scopes_all_apply(): void
    {
        ScopedArticle::addGlobalScope(new PublishedScope());
        ScopedArticle::addGlobalScope('undeleted', function (ModelBuilder $builder): void {
            $builder->whereNull('deleted_at');
        });

        $this->assertSame(1, ScopedArticle::query()->count());
    }

    // ─── Opting out ───────────────────────────────────────

    public function test_without_global_scope_leaves_one_off(): void
    {
        ScopedArticle::addGlobalScope(new PublishedScope());
        ScopedArticle::addGlobalScope('undeleted', function (ModelBuilder $builder): void {
            $builder->whereNull('deleted_at');
        });

        $this->assertSame(2, ScopedArticle::withoutGlobalScope(PublishedScope::class)->count());
        $this->assertSame(2, ScopedArticle::withoutGlobalScope('undeleted')->count());
    }

    public function test_without_global_scopes_leaves_them_all_off(): void
    {
        ScopedArticle::addGlobalScope(new PublishedScope());

        $this->assertSame(3, ScopedArticle::withoutGlobalScopes()->count());
    }

    public function test_opting_out_still_chains(): void
    {
        ScopedArticle::addGlobalScope(new PublishedScope());

        $this->assertSame(
            1,
            ScopedArticle::withoutGlobalScope(PublishedScope::class)
                ->whereNull('published_at')
                ->count()
        );
    }

    // ─── Soft deletes run on this mechanism ───────────────

    public function test_soft_deletes_registers_its_scope_on_boot(): void
    {
        new TrashableArticle();

        $this->assertTrue(TrashableArticle::hasGlobalScope(SoftDeletingScope::class));
    }

    public function test_soft_deletes_hides_trashed_rows(): void
    {
        $this->assertSame(2, TrashableArticle::query()->count());
    }

    public function test_with_trashed_includes_them(): void
    {
        $this->assertSame(3, TrashableArticle::withTrashed()->count());
    }

    public function test_only_trashed_returns_just_them(): void
    {
        $this->assertSame(1, TrashableArticle::onlyTrashed()->count());
    }

    /** withTrashed() drops the soft-delete scope, not every other one. */
    public function test_with_trashed_keeps_other_scopes(): void
    {
        TrashableArticle::addGlobalScope(new PublishedScope());

        $this->assertSame(2, TrashableArticle::withTrashed()->count());
    }
}

class ScopedArticle extends Model
{
    protected $table = 'articles';

    protected $guarded = [];

    public $timestamps = false;
}

class TrashableArticle extends Model
{
    use SoftDeletes;

    protected $table = 'articles';

    protected $guarded = [];

    public $timestamps = false;
}

class PublishedScope implements Scope
{
    public function apply(ModelBuilder $builder, Model $model): void
    {
        $builder->whereNotNull('published_at');
    }
}
