<?php

namespace Nitro\Foundation\Providers;

use Nitro\Exceptions\ExceptionHandler;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Validation\ValidationException;

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
     * Register custom exception handlers here.
     * 
     * Examples:
     * 
     *   $handler->register(ValidationException::class, function ($e, $container) {
     *       return Response::json(['errors' => $e->errors()], 422);
     *   });
     * 
     *   $handler->reportUsing(PaymentException::class, function ($e, $container) {
     *       $container->get(SlackNotifier::class)->alert($e->getMessage());
     *   });
     * 
     *   $handler->dontReport([
     *       NotFoundException::class,
     *       ValidationException::class,
     *   ]);
     */
    public function boot(ExceptionHandler $handler): void
    {
        // Validation failures convert to a redirect-back (web) or 422 JSON (AJAX).
        // This conversion lives in the Foundation exception layer — like Laravel's
        // Handler::invalid()/invalidJson() — so the Validation layer stays free of
        // any Http dependency (no Http↔Validation cycle).
        $handler->respondUsing(
            ValidationException::class,
            function (ValidationException $e, Request $request): Response {
                $wantsJson = $request->ajax()
                    || str_contains(strtolower((string) $request->header('accept', '')), 'application/json');

                return $wantsJson
                    ? Response::json([
                        'message' => 'The given data was invalid.',
                        'errors'  => $e->errors()->all(),
                    ], $e->status)
                    : back()->withInput()->withErrors($e->errors());
            }
        );

        // Expected control-flow exceptions are not errors to log. Without this
        // every 404/403/419 (any HttpException) hits the error log, so scanner
        // and bot traffic floods it. Mirrors Laravel's internal don't-report list.
        $handler->dontReport([
            ValidationException::class,
            \Nitro\Exceptions\HttpException::class,
            \Nitro\Http\Exceptions\HttpResponseException::class,
        ]);
    }
}