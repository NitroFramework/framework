<?php

namespace Nitro\Events;

use Nitro\Broadcasting\BroadcastManager;
use Nitro\Database\DB;
use Nitro\Events\Contracts\Dispatcher as DispatcherContract;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the event dispatcher.
 *
 * Registered by the Application itself, before any bootstrapper or configured
 * provider runs: every later provider may listen for or raise an event, so
 * the bus has to exist before they do.
 */
class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Built via a closure rather than by class name so the dispatcher gets
         * the container: it needs one to resolve a listener named by class, and
         * to reach the queue for a listener that should not run in the request.
         */
        $this->container->singleton('events', static function ($container) {
            $dispatcher = new Dispatcher();
            $dispatcher->setContainer($container);

            /*
             * How an after-commit event finds out whether there is a commit to
             * wait for. Null when nothing has started a transaction, which is
             * also the answer in an application with no database — and in both
             * cases dispatching immediately is correct.
             */
            $dispatcher->setTransactionManagerResolver(
                static fn (): ?object => DB::transactions()
            );

            /*
             * How an event implementing ShouldBroadcast reaches listening
             * clients. Resolved on each dispatch rather than captured, so the
             * deferred broadcast provider is only registered by an application
             * that actually fires a broadcastable event — and null when
             * nothing is bound, which is the answer for one that never does.
             */
            $dispatcher->setBroadcastResolver(
                static fn (): ?object => $container->has(BroadcastManager::class)
                    ? $container->resolve(BroadcastManager::class)
                    : null
            );

            return $dispatcher;
        });

        /*
         * The contract is the name the framework resolves by; the concrete is
         * aliased too so an application that named it keeps working. Binding
         * something else to the contract replaces the bus everywhere.
         */
        $this->container->alias('events', DispatcherContract::class);
        $this->container->alias('events', Dispatcher::class);
    }
}
