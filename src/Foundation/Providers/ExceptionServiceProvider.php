<?php

namespace Nitro\Foundation\Providers;

use Nitro\Auth\Exceptions\AuthenticationException;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Validation\ValidationException;
use Nitro\View\Contracts\ViewFinder;

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
        $this->container->singleton(ExceptionHandler::class);
        $this->container->alias(ExceptionHandler::class, 'exceptions');
    }

    /**
     * Register the nitro-errors:: view namespace holding the framework's default
     * error pages. An application overrides any of them with its own
     * resources/views/errors/{code}.blade.php — the handler looks there first.
     */
    protected function registerErrorViews(): void
    {
        if (! $this->container->has(ViewFinder::class)) {
            return;
        }

        // Registered on the finder, which is what actually holds the namespace
        // map. Going through the engine would construct the whole Blade stack
        // during boot just to record a path that only an error page reads.
        $this->container->resolve(ViewFinder::class)
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
     *       $container->resolve(SlackNotifier::class)->alert($exception->getMessage());
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

        /*
         * Nobody is signed in.
         *
         * The choice a browser and an API client need is different, and this
         * is the one place that can make it for both: a browser is sent to the
         * login page the exception carries, while anything asking for JSON —
         * or a request with no login page to send it to — gets the 401 that
         * says what actually happened. A fetch() following a 302 would parse
         * the login form as its response and report nothing useful.
         */
        $handler->renderableResponse(
            AuthenticationException::class,
            function (AuthenticationException $exception, Request $request): Response {
                $redirect = $exception->redirectTo();

                return ($request->expectsJson() || $redirect === null)
                    ? Response::json(['message' => $exception->getMessage()], 401)
                    : Response::redirect($redirect, 302);
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