<?php

namespace Tests\Unit\Inertia;

use Nitro\Foundation\Contracts\ResetsBetweenRequests;
use Nitro\Inertia\ResponseFactory;
use PHPUnit\Framework\TestCase;

/**
 * What the response factory keeps when a worker moves to the next request.
 *
 * The factory is a singleton, so under a worker one object serves every
 * request, and share() merges rather than replaces. A shared prop left behind
 * is the previous visitor's — their user, their flash messages — handed to
 * whoever asks next, and no amount of correct middleware in the next request
 * removes a key that middleware does not set again.
 */
class InertiaWorkerResetTest extends TestCase
{
    public function test_the_factory_takes_part_in_the_worker_reset(): void
    {
        $this->assertInstanceOf(ResetsBetweenRequests::class, new ResponseFactory());
    }

    public function test_shared_props_do_not_survive_into_the_next_request(): void
    {
        $factory = new ResponseFactory();

        $factory->share('user', ['id' => 1, 'email' => 'first@example.test']);
        $factory->share('flash', ['message' => 'Saved']);

        $this->assertSame(['id' => 1, 'email' => 'first@example.test'], $factory->getShared('user'));

        $factory->resetBetweenRequests();

        $this->assertSame([], $factory->getShared());
    }

    /**
     * The root view and the asset version are configuration, set where the
     * application is assembled rather than per visit, so a reset keeps them.
     */
    public function test_configuration_survives_the_reset(): void
    {
        $factory = new ResponseFactory();

        $factory->setRootView('layouts.app');
        $factory->version('build-1234');

        $factory->resetBetweenRequests();

        $this->assertSame('build-1234', $factory->getVersion());

        $rootView = (new \ReflectionClass($factory))->getProperty('rootView');

        $this->assertSame('layouts.app', $rootView->getValue($factory));
    }
}
