<?php

namespace Nitro\Foundation\Bootstrap;

use ErrorException;
use Throwable;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Foundation\Application;

/**
 * Bootstrap: HandleExceptions
 * 
 * Thin bootstrapper — its ONLY job is to wire PHP's error/exception/shutdown
 * handlers to the centralized ExceptionHandler.
 * 
 * No rendering logic lives here. Everything delegates to ExceptionHandler.
 */
class HandleExceptions implements BootstrapperInterface
{
    private ExceptionHandler $handler;

    public function bootstrap(Application $app): void
    {
        // Resolve the centralized handler from the container
        $this->handler = $app->getContainer()->make(ExceptionHandler::class);

        error_reporting(E_ALL);

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Convert PHP errors to ErrorException.
     */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): void
    {
        if (error_reporting() & $level) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }
    }

    /**
     * Handle uncaught exceptions — delegate to ExceptionHandler.
     */
    public function handleException(Throwable $e): void
    {
        try {
            $this->handler->handleAndExit($e);
        } catch (Throwable $fallback) {
            $this->renderFallback($e, $fallback);
        }
    }

    /**
     * Handle fatal errors caught during shutdown.
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $this->handleException(new ErrorException(
                $error['message'], 0, $error['type'], $error['file'], $error['line']
            ));
        }
    }

    /**
     * Last resort — if even ExceptionHandler fails, show raw text.
     */
    private function renderFallback(Throwable $original, Throwable $handlerError): never
    {
        while (ob_get_level() > 0) ob_end_clean();

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
        }

        echo "=== FATAL: Exception handler itself failed ===\n\n";
        echo "Original: {$original->getMessage()}\n";
        echo "  at {$original->getFile()}:{$original->getLine()}\n\n";
        echo "Handler:  {$handlerError->getMessage()}\n";
        echo "  at {$handlerError->getFile()}:{$handlerError->getLine()}\n\n";
        echo $original->getTraceAsString();

        exit(1);
    }
}