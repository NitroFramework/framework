<?php

namespace Nitro\Foundation\Bootstrap;

use ErrorException;
use Throwable;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Foundation\Application;
use Nitro\Support\Logger;

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
    /** PHP error levels that are notices about the future, not failures now. */
    private const DEPRECATION_LEVELS = [E_DEPRECATED, E_USER_DEPRECATED];

    /** Fatal error types that only surface at shutdown. */
    private const FATAL_LEVELS = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];

    /**
     * Memory held back at bootstrap and released the moment an error arrives, so
     * a request that dies of memory exhaustion still has room to render the page
     * that says so. Without it an OOM produces a blank response.
     */
    private static ?string $reservedMemory = null;

    private ExceptionHandler $handler;

    public function bootstrap(Application $app): void
    {
        self::$reservedMemory = str_repeat('x', 32768);

        // Resolve the centralized handler from the container
        $this->handler = $app->getContainer()->resolve(ExceptionHandler::class);

        // Buffers already open belong to whoever is hosting us (a Thrust worker,
        // a test harness); the handler unwinds only what the request opened.
        ExceptionHandler::$initialObLevel = ob_get_level();

        error_reporting(E_ALL);

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);

        // PHP must not print its own error output alongside ours — on a server
        // with display_errors=On (XAMPP's default) raw warnings would be emitted
        // ahead of the rendered page and corrupt the response. Left alone while
        // testing so PHPUnit can still surface what it needs.
        if ($app->environment() !== 'testing') {
            ini_set('display_errors', 'Off');
        }
    }

    /**
     * Convert PHP errors to ErrorException — except deprecations, which are
     * recorded and allowed to continue.
     *
     * A deprecation is PHP telling you something will break in a future version,
     * not that this request failed. Throwing on it means one deprecation notice
     * anywhere in vendor/ takes the whole request down, which is why this
     * branches before the throw.
     */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): void
    {
        if (in_array($level, self::DEPRECATION_LEVELS, true)) {
            $this->handleDeprecation($message, $file, $line);
            return;
        }

        if (error_reporting() & $level) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }
    }

    /** Log a deprecation and carry on. */
    private function handleDeprecation(string $message, string $file, int $line): void
    {
        if (! (error_reporting() & E_DEPRECATED)) {
            return;
        }

        Logger::warning($message, ['file' => $file, 'line' => $line, 'type' => 'deprecation']);
    }

    /**
     * Handle uncaught exceptions — delegate to ExceptionHandler.
     */
    public function handleException(Throwable $exception): void
    {
        self::$reservedMemory = null;

        try {
            $this->handler->handleAndExit($exception);
        } catch (Throwable $fallback) {
            $this->renderFallback($exception, $fallback);
        }
    }

    /**
     * Handle fatal errors caught during shutdown.
     */
    public function handleShutdown(): void
    {
        self::$reservedMemory = null;

        $error = error_get_last();

        if ($error && in_array($error['type'], self::FATAL_LEVELS, true)) {
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