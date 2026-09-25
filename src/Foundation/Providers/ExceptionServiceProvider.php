<?php

namespace Nitro\Foundation\Providers;

use Nitro\Auth\Exceptions\AuthenticationException;
use Nitro\Database\Model\ModelNotFoundException;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Validation\ValidationException;
use Nitro\View\Contracts\ViewFinder;

/**
 * Register the exception handler and how framework exceptions render.
 */
class ExceptionServiceProvider extends ServiceProvider
{
    /** Register the exception handler. */
    public function register(): void
    {
        $this->container->singleton(ExceptionHandler::class);
        $this->container->alias(ExceptionHandler::class, 'exceptions');
    }

    /**
     * Register the nitro-errors view namespace holding the default error pages.
     *
     * An application overrides a page with its own resources/views/errors/{code}.blade.php.
     */
    protected function registerErrorViews(): void
    {
        if (! $this->container->has(ViewFinder::class)) {
            return;
        }

        $this->container->resolve(ViewFinder::class)
            ->addNamespace('nitro-errors', __DIR__ . '/../../Exceptions/views');
    }

    /**
     * Render validation and authentication failures, and skip reporting a missing record.
     *
     * A validation failure redirects back with errors, or answers 422 JSON when JSON is
     * expected. An unauthenticated request redirects to the login page, or answers 401
     * when JSON is expected or there is no login page.
     */
    public function boot(ExceptionHandler $handler): void
    {
        $this->registerErrorViews();

        $handler->renderableResponse(
            ValidationException::class,
            function (ValidationException $exception, Request $request): Response {
                return $request->expectsJson()
                    ? Response::json([
                        'message' => 'The given data was invalid.',
                        'errors'  => $exception->errors()->all(),
                    ], $exception->status)
                    : back()->withInput()->withErrors($exception->errors());
            }
        );

        $handler->renderableResponse(
            AuthenticationException::class,
            function (AuthenticationException $exception, Request $request): Response {
                $redirect = $exception->redirectTo();

                return ($request->expectsJson() || $redirect === null)
                    ? Response::json(['message' => $exception->getMessage()], 401)
                    : Response::redirect($redirect, 302);
            }
        );

        $handler->dontReport([
            ModelNotFoundException::class,
        ]);

        $handler->dontReportDuplicates();
    }
}
