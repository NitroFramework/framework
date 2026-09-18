<?php

namespace Nitro\Support;

use Closure;
use Nitro\Container\Contracts\ContainerInterface;
use RuntimeException;

/**
 * Passes a value through a series of stages, each able to act before and after
 * the next one runs.
 *
 *     Pipeline::make($container)
 *         ->send($request)
 *         ->through([Authenticate::class, VerifyCsrfToken::class])
 *         ->then(fn ($request) => $router->dispatch($request));
 *
 * A stage is a callable, an object carrying the method named by {@see via()},
 * or a class name to resolve from the container.
 */
class Pipeline
{
    /** The value travelling through the stages. */
    protected mixed $passable = null;

    /** @var array<int, mixed> Stages, in the order they run. */
    protected array $pipes = [];

    /** The method a class or object stage is called through. */
    protected string $method = 'handle';

    public function __construct(
        protected ?ContainerInterface $container = null,
    ) {}

    /** A pipeline that resolves class-name stages from the given container. */
    public static function make(?ContainerInterface $container = null): static
    {
        return new static($container);
    }

    /** Set the value to send through the stages. */
    public function send(mixed $passable): static
    {
        $this->passable = $passable;

        return $this;
    }

    /**
     * Set the stages, replacing any already set.
     *
     * @param array<int, mixed>|mixed $pipes An array of stages, or several arguments.
     */
    public function through(mixed $pipes): static
    {
        $this->pipes = is_array($pipes) ? array_values($pipes) : func_get_args();

        return $this;
    }

    /**
     * Append stages to those already set.
     *
     * @param array<int, mixed>|mixed $pipes An array of stages, or several arguments.
     */
    public function pipe(mixed $pipes): static
    {
        $appended = is_array($pipes) ? array_values($pipes) : func_get_args();

        array_push($this->pipes, ...$appended);

        return $this;
    }

    /** Set the method that class and object stages are called through. */
    public function via(string $method): static
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Run the stages, then the destination.
     *
     * Stages are folded in reverse, so the first declared is the outermost.
     *
     * @return mixed Whatever the outermost stage returns.
     */
    public function then(Closure $destination): mixed
    {
        $next = $destination;

        foreach (array_reverse($this->pipes) as $pipe) {
            $next = $this->wrap($pipe, $next);
        }

        return $next($this->passable);
    }

    /** Run the stages and return the value as they left it. */
    public function thenReturn(): mixed
    {
        return $this->then(static fn (mixed $passable): mixed => $passable);
    }

    /**
     * Wrap one stage around the rest of the pipeline.
     *
     * Stages resolve now rather than when they run, so a stage that does not
     * exist is reported even if an earlier one would short-circuit first.
     *
     * @throws RuntimeException When the stage cannot be called.
     */
    protected function wrap(mixed $pipe, Closure $next): Closure
    {
        if ($pipe instanceof Closure) {
            return static fn (mixed $passable): mixed => $pipe($passable, $next);
        }

        if (is_string($pipe)) {
            $pipe = $this->resolve($pipe);
        }

        if (is_object($pipe) && method_exists($pipe, $this->method)) {
            $method = $this->method;

            return static fn (mixed $passable): mixed => $pipe->{$method}($passable, $next);
        }

        if (is_callable($pipe)) {
            return static fn (mixed $passable): mixed => $pipe($passable, $next);
        }

        throw new RuntimeException(sprintf(
            'Pipeline stage [%s] is not callable and has no %s() method.',
            get_debug_type($pipe),
            $this->method
        ));
    }

    /**
     * Build a stage named by class, from the container when there is one.
     *
     * @throws RuntimeException When the class does not exist.
     */
    protected function resolve(string $pipe): object
    {
        if ($this->container !== null) {
            return $this->container->createOrResolve($pipe);
        }

        if (! class_exists($pipe)) {
            throw new RuntimeException("Pipeline stage [{$pipe}] does not exist.");
        }

        return new $pipe();
    }
}
