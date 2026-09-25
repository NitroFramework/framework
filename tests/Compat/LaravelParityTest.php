<?php

namespace Nitro\Tests\Compat;

use Illuminate\Events\Dispatcher;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Context;
use Nitro\Tests\Fixtures\Classes\ContextJob;
use Nitro\Tests\Fixtures\Classes\ObservedModel;
use Nitro\Tests\TestCase;

/**
 * Behaviour that stock Laravel gets from the providers Nitro replaces with components. Each test
 * runs one scenario against a stock Laravel application and a Nitro application over the same
 * fixture app, asserts what Laravel does, and asserts Nitro does the same.
 */
class LaravelParityTest extends TestCase
{
    protected function tearDown(): void
    {
        ContextJob::$seen = ContextJob::$payloadContext = null;

        parent::tearDown();
    }

    /**
     * Each application boots its models again, so listeners registered in boot()/booted() attach
     * to that application's dispatcher (DatabaseServiceProvider clears booted models).
     */
    public function test_models_boot_again_for_each_application(): void
    {
        $result = $this->parity(function (callable $boot) {
            $listeners = [];

            foreach ([1, 2] as $application) {
                $app = $boot();
                new ObservedModel;
                $listeners[] = count($app['events']->getListeners('eloquent.creating: '.ObservedModel::class));
                $this->flushGlobalState();
            }

            return $listeners;
        });

        $this->assertSame([1, 1], $result['laravel']);
        $this->assertSame($result['laravel'], $result['nitro']);
    }

    /**
     * The context repository is scoped: forgetScopedInstances() (the queue worker between jobs,
     * Octane between requests) starts the next job with an empty context.
     */
    public function test_context_is_forgotten_with_scoped_instances(): void
    {
        $result = $this->parity(function (callable $boot) {
            $app = $boot();
            $first = $app->make(ContextRepository::class);
            $first->add('request_id', 'job-1');
            $sameWithinJob = $app->make(ContextRepository::class) === $first;

            $app->forgetScopedInstances();
            $next = $app->make(ContextRepository::class);

            return [$sameWithinJob, $next === $first, $next->get('request_id')];
        });

        $this->assertSame([true, false, null], $result['laravel']);
        $this->assertSame($result['laravel'], $result['nitro']);
    }

    /**
     * On the sync queue driver, the payload carries the dispatching context and the job runs
     * with it.
     */
    public function test_sync_queue_job_payload_carries_the_context(): void
    {
        $result = $this->parity(function (callable $boot) {
            $boot();
            Context::add('trace_id', 'abc-123');
            Context::addHidden('secret', 's3');

            ContextJob::dispatch();

            return [ContextJob::$seen, ContextJob::$payloadContext];
        });

        $this->assertSame(['trace_id' => 'abc-123'], $result['laravel'][0]);
        $this->assertIsArray($result['laravel'][1]);
        $this->assertSame($result['laravel'], $result['nitro']);
    }

    /**
     * A job being processed (as by a queue worker, in a process with no context of its own)
     * restores the context from its payload.
     */
    public function test_processing_a_job_hydrates_its_context(): void
    {
        $payloadContext = self::dehydrated(['trace_id' => 'abc-123']);

        $result = $this->parity(function (callable $boot) use ($payloadContext) {
            $app = $boot();
            Context::flush();

            $job = new SyncJob($app, json_encode(['job' => 'none', 'illuminate:log:context' => $payloadContext]), 'sync', 'default');
            $app['events']->dispatch(new JobProcessing('sync', $job));

            return Context::all();
        });

        $this->assertSame(['trace_id' => 'abc-123'], $result['laravel']);
        $this->assertSame($result['laravel'], $result['nitro']);
    }

    /**
     * A console process started with __LARAVEL_CONTEXT (the Concurrency process driver) starts
     * with that context.
     */
    public function test_console_process_hydrates_context_from_the_environment(): void
    {
        $encoded = json_encode(self::dehydrated(['trace_id' => 'from-parent']));
        putenv("__LARAVEL_CONTEXT={$encoded}");
        $_SERVER['__LARAVEL_CONTEXT'] = $_ENV['__LARAVEL_CONTEXT'] = $encoded;

        try {
            $result = $this->parity(fn (callable $boot) => $boot()->make(ContextRepository::class)->all());
        } finally {
            putenv('__LARAVEL_CONTEXT');
            unset($_SERVER['__LARAVEL_CONTEXT'], $_ENV['__LARAVEL_CONTEXT']);
        }

        $this->assertSame(['trace_id' => 'from-parent'], $result['laravel']);
        $this->assertSame($result['laravel'], $result['nitro']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function dehydrated(array $data): array
    {
        return (new ContextRepository(new Dispatcher))->add($data)->dehydrate();
    }
}
