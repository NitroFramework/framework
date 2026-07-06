<?php

namespace Nitro\Support\Concerns;

/**
 * Turns a plain class into a single-action class.
 *
 * The class does its work in a handle() method and can then be used three ways:
 *   - as a route handler:  Route::post('/users', CreateUser::class)
 *   - dispatched directly:  CreateUser::run($input)
 *   - resolved:             CreateUser::make()
 *
 * There is no base class to extend — compose this trait onto any (ideally final)
 * class. To run an action on the queue, compose it with the Queue layer's Job /
 * Dispatchable rather than adding queue concerns here.
 */
trait AsAction
{
    /**
     * Resolve the action from the container with its constructor dependencies
     * autowired.
     */
    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Resolve the action and run its handle() with the given arguments.
     *
     * Constructor dependencies autowire; the arguments are forwarded to handle()
     * positionally, so CreateUser::run($a, $b) calls handle($a, $b).
     *
     * @param mixed ...$arguments Arguments forwarded straight to handle().
     */
    public static function run(mixed ...$arguments): mixed
    {
        return app(static::class)->handle(...$arguments);
    }
}
