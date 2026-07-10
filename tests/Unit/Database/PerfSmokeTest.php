<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Nitro\Database\Connection;
use Nitro\Database\Query\Grammar\MySqlGrammar;
use Nitro\Database\Query\QueryBuilder;

/**
 * Sanity-check that the perf hooks actually reduce work. These are
 * NOT precision benchmarks — they assert directional improvements that
 * regressions would unmistakably violate (e.g., wrap() caches its result;
 * a thousand identical wraps don't make a thousand regex matches).
 */
class PerfSmokeTest extends TestCase
{
    public function test_wrap_is_memoized(): void
    {
        $g = new MySqlGrammar();

        // Burn it in once.
        $first = $g->wrap('users.id');
        // Re-execute many times — second call must be a cache hit, not a
        // re-parse. We verify by reflecting on the wrapCache.
        for ($i = 0; $i < 1000; $i++) {
            $g->wrap('users.id');
            $g->wrap('users.name');
            $g->wrap('posts.title');
            $g->wrap('*');
        }

        $p = new \ReflectionProperty(\Nitro\Database\Query\Grammar\Grammar::class, 'wrapCache');
        $p->setAccessible(true);
        $cache = $p->getValue($g);

        $this->assertArrayHasKey('users.id', $cache);
        $this->assertArrayHasKey('users.name', $cache);
        $this->assertArrayHasKey('posts.title', $cache);
        $this->assertArrayHasKey('*', $cache);
    }

    public function test_operator_validation_is_memoized(): void
    {
        $g = new MySqlGrammar();
        $r = new \ReflectionMethod($g, 'validateOperator');
        $r->setAccessible(true);

        for ($i = 0; $i < 100; $i++) {
            $r->invoke($g, '=');
            $r->invoke($g, '<>');
            $r->invoke($g, 'LIKE');
        }

        $cacheProp = new \ReflectionProperty(\Nitro\Database\Query\Grammar\Grammar::class, 'operatorCache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue($g);

        $this->assertArrayHasKey('=', $cache);
        $this->assertArrayHasKey('<>', $cache);
        $this->assertArrayHasKey('LIKE', $cache);
    }

    public function test_get_bindings_runs_in_constant_time(): void
    {
        // getBindings() is called on every execution path. Used to do
        // foreach + array_merge — now it's a single flat spread. The
        // test asserts the call is dirt-cheap for typical builders.
        $b = (new QueryBuilder($this->createMock(Connection::class), new MySqlGrammar()))
            ->from('users')
            ->where('a', 1)
            ->where('b', 2)
            ->where('c', 3);

        $start = microtime(true);
        for ($i = 0; $i < 10000; $i++) {
            $b->getBindings();
        }
        $elapsed = microtime(true) - $start;

        // Conservative ceiling. 10k calls should be well under 100ms on any
        // machine; if regression bumps to seconds, this catches it.
        $this->assertLessThan(0.5, $elapsed, "getBindings() called 10000 times took {$elapsed}s");
    }

    public function test_cast_cache_avoids_repeat_datetime(): void
    {
        $model = new class extends \Nitro\Database\Model\Model {
            protected string $table = 't';
            protected array $casts = ['created_at' => 'datetime'];
        };

        $row = (object) ['created_at' => '2024-01-15 12:34:56'];
        $m = $model->newFromObject($row);

        $first = $m->created_at;
        $this->assertInstanceOf(\DateTime::class, $first);
        for ($i = 0; $i < 100; $i++) {
            $repeat = $m->created_at;
            $this->assertSame($first, $repeat, 'cast cache must return the same DateTime instance');
        }
    }

    public function test_cast_cache_invalidates_on_set(): void
    {
        $model = new class extends \Nitro\Database\Model\Model {
            protected string $table = 't';
            protected array $casts = ['flag' => 'bool'];
        };
        $m = $model->newFromObject((object) ['flag' => 0]);
        $this->assertFalse($m->flag);
        $m->flag = 1;
        $this->assertTrue($m->flag, 'changing the raw value must invalidate the cast cache');
    }
}
