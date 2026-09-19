<?php

namespace Tests\Unit\Queue;

use Nitro\Cache\RateLimiter;
use Nitro\Cache\Repository;
use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Container\Container;
use Nitro\Queue\Contracts\ShouldBeUnique;
use Nitro\Queue\InteractsWithQueue;
use Nitro\Queue\Job;
use Nitro\Queue\Middleware\FailOnException;
use Nitro\Queue\Middleware\RateLimited;
use Nitro\Queue\Middleware\Release;
use Nitro\Queue\Middleware\Skip;
use Nitro\Queue\Middleware\ThrottlesExceptions;
use Nitro\Queue\Middleware\WithoutOverlapping;
use Nitro\Queue\UniqueLock;
use Nitro\Support\Pipeline;
use PHPUnit\Framework\TestCase;

/**
 * Job middleware, queue interactions from inside a job, and unique jobs.
 */
class QueueFeaturesTest extends TestCase
{
    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(new Container());

        $this->cache = new Repository(new ArrayStore());

        Container::getInstance()->instance(Repository::class, $this->cache);
        Container::getInstance()->instance(RateLimiter::class, new RateLimiter($this->cache));
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());
        parent::tearDown();
    }

    /** Run a job through middleware the way the worker does. */
    private function through(array $middleware, Job $job, ?callable $destination = null): mixed
    {
        return Pipeline::make(Container::getInstance())
            ->send($job)
            ->through($middleware)
            ->then($destination ?? static function (Job $job): string {
                $job->ran = true;

                return 'ran';
            });
    }

    // ─── Skip ─────────────────────────────────────────────

    public function test_skip_when_drops_the_job(): void
    {
        $job = new FeatureJob();

        $this->assertNull($this->through([Skip::when(true)], $job));
        $this->assertFalse($job->ran);
    }

    public function test_skip_when_false_runs_the_job(): void
    {
        $job = new FeatureJob();

        $this->assertSame('ran', $this->through([Skip::when(false)], $job));
        $this->assertTrue($job->ran);
    }

    public function test_skip_unless_inverts_the_condition(): void
    {
        $this->assertNull($this->through([Skip::unless(false)], new FeatureJob()));
        $this->assertSame('ran', $this->through([Skip::unless(true)], new FeatureJob()));
    }

    public function test_skip_accepts_a_closure(): void
    {
        $this->assertNull($this->through([Skip::when(fn (): bool => true)], new FeatureJob()));
    }

    // ─── Release ──────────────────────────────────────────

    public function test_release_puts_the_job_back(): void
    {
        $job = (new FeatureJob())->withFakeQueueInteractions();

        $this->assertNull($this->through([Release::when(true)->for(45)], $job));

        $job->assertReleased(45);
        $this->assertFalse($job->ran);
    }

    public function test_release_unless_runs_when_satisfied(): void
    {
        $job = (new FeatureJob())->withFakeQueueInteractions();

        $this->assertSame('ran', $this->through([Release::unless(true)], $job));

        $job->assertNotReleased();
    }

    // ─── FailOnException ──────────────────────────────────

    public function test_a_listed_exception_fails_the_job(): void
    {
        $job = (new FeatureJob())->withFakeQueueInteractions();

        $this->through([new FailOnException([\DomainException::class])], $job, static function (): void {
            throw new \DomainException('no retry will help');
        });

        $job->assertFailed()->assertFailedWith(\DomainException::class);
    }

    public function test_an_unlisted_exception_is_rethrown(): void
    {
        $job = (new FeatureJob())->withFakeQueueInteractions();

        $this->expectException(\RuntimeException::class);

        $this->through([new FailOnException([\DomainException::class])], $job, static function (): void {
            throw new \RuntimeException('retry might help');
        });
    }

    // ─── WithoutOverlapping ───────────────────────────────

    public function test_a_second_job_on_the_same_key_is_released(): void
    {
        $first = (new FeatureJob())->withFakeQueueInteractions();
        $second = (new FeatureJob())->withFakeQueueInteractions();

        $middleware = new WithoutOverlapping('tenant-1');

        // Hold the lock while the second job tries for it.
        $this->through([$middleware], $first, function () use ($middleware, $second): void {
            $this->through([(new WithoutOverlapping('tenant-1'))->releaseAfter(30)], $second);
        });

        $second->assertReleased(30);
        $this->assertFalse($second->ran);
    }

    public function test_the_lock_is_given_back_afterwards(): void
    {
        $middleware = new WithoutOverlapping('tenant-2');

        $this->through([$middleware], new FeatureJob());

        $second = new FeatureJob();
        $this->through([new WithoutOverlapping('tenant-2')], $second);

        $this->assertTrue($second->ran);
    }

    public function test_the_lock_is_given_back_when_the_job_throws(): void
    {
        try {
            $this->through([new WithoutOverlapping('tenant-3')], new FeatureJob(), static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $second = new FeatureJob();
        $this->through([new WithoutOverlapping('tenant-3')], $second);

        $this->assertTrue($second->ran);
    }

    public function test_dont_release_drops_the_blocked_job(): void
    {
        $second = (new FeatureJob())->withFakeQueueInteractions();

        $this->through([new WithoutOverlapping('tenant-4')], new FeatureJob(), function () use ($second): void {
            $this->through([(new WithoutOverlapping('tenant-4'))->dontRelease()], $second);
        });

        $second->assertNotReleased();
        $this->assertFalse($second->ran);
    }

    // ─── RateLimited ──────────────────────────────────────

    public function test_jobs_run_until_the_limit_is_reached(): void
    {
        $middleware = fn (): RateLimited => (new RateLimited('reports'))->allow(2)->every(60);

        $this->assertSame('ran', $this->through([$middleware()], new FeatureJob()));
        $this->assertSame('ran', $this->through([$middleware()], new FeatureJob()));

        $third = (new FeatureJob())->withFakeQueueInteractions();

        $this->assertNull($this->through([$middleware()->releaseAfter(15)], $third));
        $third->assertReleased(15);
    }

    public function test_a_limited_job_can_be_dropped_instead(): void
    {
        $middleware = fn (): RateLimited => (new RateLimited('drops'))->allow(1)->every(60);

        $this->through([$middleware()], new FeatureJob());

        $second = (new FeatureJob())->withFakeQueueInteractions();

        $this->assertNull($this->through([$middleware()->dontRelease()], $second));
        $second->assertNotReleased();
    }

    // ─── ThrottlesExceptions ──────────────────────────────

    public function test_the_circuit_opens_after_repeated_failures(): void
    {
        $throwing = static function (): void {
            throw new \RuntimeException('dependency down');
        };

        for ($i = 0; $i < 2; $i++) {
            try {
                $this->through([new ThrottlesExceptions(2, 10)], new FeatureJob(), $throwing);
            } catch (\RuntimeException) {
                // counted
            }
        }

        $blocked = (new FeatureJob())->withFakeQueueInteractions();

        $this->assertNull($this->through([(new ThrottlesExceptions(2, 10))->backoff(30)], $blocked, $throwing));

        $blocked->assertReleased(30);
        $this->assertFalse($blocked->ran);
    }

    public function test_an_unlisted_exception_does_not_count(): void
    {
        $middleware = fn (): ThrottlesExceptions => (new ThrottlesExceptions(1, 10))
            ->by('selective')
            ->when([\DomainException::class]);

        try {
            $this->through([$middleware()], new FeatureJob(), static function (): void {
                throw new \RuntimeException('different kind');
            });
        } catch (\RuntimeException) {
            // not counted
        }

        $next = new FeatureJob();
        $this->through([$middleware()], $next);

        $this->assertTrue($next->ran, 'the circuit should still be closed');
    }

    // ─── InteractsWithQueue ───────────────────────────────

    public function test_a_job_can_record_its_own_interactions(): void
    {
        $job = (new FeatureJob())->withFakeQueueInteractions();

        $job->release(60);

        $job->assertReleased(60)->assertNotDeleted()->assertNotFailed();
    }

    public function test_delete_and_fail_are_recorded(): void
    {
        $job = (new FeatureJob())->withFakeQueueInteractions();
        $job->delete();
        $job->assertDeleted();

        $failing = (new FeatureJob())->withFakeQueueInteractions();
        $failing->fail(new \DomainException('gave up'));
        $failing->assertFailed()->assertFailedWith(new \DomainException('gave up'));
    }

    public function test_an_assertion_that_does_not_hold_raises(): void
    {
        $job = (new FeatureJob())->withFakeQueueInteractions();

        $this->expectException(\RuntimeException::class);

        $job->assertDeleted();
    }

    // ─── Unique jobs ──────────────────────────────────────

    public function test_a_unique_job_may_only_be_claimed_once(): void
    {
        $lock = new UniqueLock($this->cache);
        $job = new UniqueFeatureJob('site-1');

        $this->assertTrue($lock->acquire($job));
        $this->assertTrue($lock->held($job));
        $this->assertFalse($lock->acquire(new UniqueFeatureJob('site-1')));
    }

    public function test_a_different_unique_id_is_a_different_claim(): void
    {
        $lock = new UniqueLock($this->cache);

        $this->assertTrue($lock->acquire(new UniqueFeatureJob('site-1')));
        $this->assertTrue($lock->acquire(new UniqueFeatureJob('site-2')));
    }

    public function test_releasing_the_claim_allows_it_again(): void
    {
        $lock = new UniqueLock($this->cache);
        $job = new UniqueFeatureJob('site-3');

        $lock->acquire($job);
        $lock->release($job);

        $this->assertTrue($lock->acquire($job));
    }
}

class FeatureJob extends Job
{
    use InteractsWithQueue;

    public bool $ran = false;

    public function handle(): void
    {
        $this->ran = true;
    }
}

class UniqueFeatureJob extends Job implements ShouldBeUnique
{
    public int $uniqueFor = 120;

    public function __construct(private string $siteId = '')
    {
    }

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(): void
    {
    }
}
