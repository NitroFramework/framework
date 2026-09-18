<?php

namespace Nitro\Foundation\Providers;

use Nitro\Exceptions\ExceptionHandler;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Validation\ValidationException;
use Nitro\View\Contracts\Engine;

/**
 * Registers the centralized ExceptionHandler.
 * 
 * Boot method is where you register custom handlers and reporters
 * for specific exception types.
 */
class ExceptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(ExceptionHandler::class, ExceptionHandler::class);
        $this->container->alias('exceptions', ExceptionHandler::class);
    }

    /**
     * Register the nitro-errors:: view namespace holding the framework's default
     * error pages. An application overrides any of them with its own
     * resources/views/errors/{code}.blade.php — the handler looks there first.
     */
    protected function registerErrorViews(): void
    {
        if (! $this->container->has(Engine::class)) {
            return;
        }

        $this->container->createOrResolve(Engine::class)
            ->addNamespace('nitro-errors', __DIR__ . '/../../Exceptions/views');
    }

    /**
     * Register custom exception handlers here.
     * 
     * Examples:
     * 
     *   $handler->register(ValidationException::class, function ($exception, $container) {
     *       return Response::json(['errors' => $exception->errors()], 422);
     *   });
     * 
     *   $handler->reportUsing(PaymentException::class, function ($exception, $container) {
     *       $container->createOrResolve(SlackNotifier::class)->alert($exception->getMessage());
     *   });
     * 
     *   $handler->dontReport([
     *       NotFoundException::class,
     *       ValidationException::class,
     *   ]);
     */
    public function boot(ExceptionHandler $handler): void
    {
        $this->registerErrorViews();

        // Validation failures convert to a redirect-back (web) or 422 JSON (API).
        // This conversion lives in the Foundation exception layer — like Laravel's
        // Handler::invalid()/invalidJson() — so the Validation layer stays free of
        // any Http dependency (no Http↔Validation cycle).
        $handler->renderableResponse(
            ValidationException::class,
            function (ValidationException $exception, Request $request): Response {
                // expectsJson() covers both the XHR header and Accept, so a REST
                // client gets 422 JSON rather than being redirected to a form.
                return $request->expectsJson()
                    ? Response::json([
                        'message' => 'The given data was invalid.',
                        'errors'  => $exception->errors()->all(),
                    ], $exception->status)
                    : back()->withInput()->withErrors($exception->errors());
            }
        );

        // A record that doesn't exist is an ordinary 404, not something to log.
        // (The framework already ignores HttpException; this covers the domain
        // exception before prepareException() turns it into one.)
        $handler->dontReport([
            \Nitro\Database\Model\ModelNotFoundException::class,
        ]);

        // Report an exception instance once, however many times it is caught
        // and rethrown on its way up.
        $handler->dontReportDuplicates();
    }
}