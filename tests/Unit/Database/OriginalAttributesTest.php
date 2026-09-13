<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use PHPUnit\Framework\TestCase;

/**
 * What a row said before this write.
 *
 * A guard in an updating() hook cannot ask the model what state it is in — by
 * the time the hook runs, the new values are already on it. "Was this a draft
 * before somebody touched it?" is answerable only from the original snapshot,
 * and a rule like "a published version may change its status and nothing else"
 * is unenforceable without it.
 */
class OriginalAttributesTest extends TestCase
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

        $connection->statement('CREATE TABLE drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, status TEXT, views INTEGER DEFAULT 0)');

        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
        Model::unsetEventDispatcher();
        DB::disconnect();

        parent::tearDown();
    }

    public function test_the_original_value_survives_a_write_to_the_model(): void
    {
        Draft::create(['title' => 'First', 'status' => 'draft']);

        $loaded = Draft::query()->first();
        $loaded->status = 'published';

        $this->assertSame('draft', $loaded->getRawOriginal('status'));
        $this->assertSame('published', $loaded->status);
    }

    public function test_the_original_is_cast_the_way_a_read_would_be(): void
    {
        Draft::create(['title' => 'First', 'status' => 'draft', 'views' => 7]);

        $loaded = Draft::query()->first();
        $loaded->views = 9;

        // Cast, so a guard comparing against an int does not compare against
        // the string PDO handed back.
        $this->assertSame(7, $loaded->getOriginal('views'));

        // Raw, so a guard that wants the column gets the column.
        $this->assertSame('7', (string) $loaded->getRawOriginal('views'));
    }

    public function test_a_column_the_row_does_not_carry_falls_back(): void
    {
        Draft::create(['title' => 'First', 'status' => 'draft']);

        $loaded = Draft::query()->first();

        $this->assertNull($loaded->getOriginal('nothing_like_this'));
        $this->assertSame('fallback', $loaded->getRawOriginal('nothing_like_this', 'fallback'));
    }

    public function test_the_whole_row_comes_back_when_no_key_is_named(): void
    {
        Draft::create(['title' => 'First', 'status' => 'draft', 'views' => 3]);

        $loaded = Draft::query()->first();
        $loaded->title = 'Renamed';

        $original = $loaded->getOriginal();

        $this->assertSame('First', $original['title']);
        $this->assertSame(3, $original['views']);
    }

    public function test_saving_moves_the_original_forward(): void
    {
        Draft::create(['title' => 'First', 'status' => 'draft']);

        $loaded = Draft::query()->first();
        $loaded->status = 'published';
        $loaded->save();

        // The row now says published, so that is what the next write compares
        // against.
        $this->assertSame('published', $loaded->getRawOriginal('status'));
        $this->assertSame([], $loaded->getDirty());
    }

    public function test_a_guard_can_tell_what_the_row_was(): void
    {
        Model::setEventDispatcher(new \Nitro\Events\Dispatcher());

        Draft::create(['title' => 'First', 'status' => 'published']);

        $seen = null;

        Draft::updating(function (Draft $draft) use (&$seen): bool {
            $seen = $draft->getRawOriginal('status');

            return true;
        });

        $loaded = Draft::query()->first();
        $loaded->title = 'Renamed';
        $loaded->save();

        $this->assertSame('published', $seen);
    }
}

class Draft extends Model
{
    protected string $table = 'drafts';
    protected array $fillable = ['title', 'status', 'views'];
    protected array $casts = ['views' => 'integer'];
    protected bool $timestamps = false;
}
