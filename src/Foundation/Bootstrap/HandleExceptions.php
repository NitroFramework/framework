<?php

namespace Nitro\Foundation\Bootstrap;

use ErrorException;
use Throwable;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Foundation\Application;
use Nitro\Support\Logger;

/**
 * Route PHP's errors, uncaught exceptions and fatal shutdowns to the exception handler.
 */
class HandleExceptions implements BootstrapperInterface
{
    /** Error levels that warn of future breakage rather than a failure now. */
    private const DEPRECATION_LEVELS = [E_DEPRECATED, E_USER_DEPRECATED];

    /** Fatal error types that only surface at shutdown. */
    private const FATAL_LEVELS = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];

    /**
     * Memory held back and released when an error arrives, so an out-of-memory failure can still render.
     */
    private static ?string $reservedMemory = null;

    private ExceptionHandler $handler;

    /**
     * Install the error, exception and shutdown handlers.
     *
     * PHP's own error display is switched off outside testing, so raw warnings never precede the rendered page.
     */
    public function bootstrap(Application $app): void
    {
        self::$reservedMemory = str_repeat('x', 32768);

        $this->handler = $app->getContainer()->resolve(ExceptionHandler::class);

        ExceptionHandler::$initialObLevel = ob_get_level();

        error_reporting(E_ALL);

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);

        if ($app->environment() !== 'testing') {
            ini_set('display_errors', 'Off');
        }
    }

    /**
     * Convert a PHP error to an ErrorException, logging a deprecation instead of throwing on it.
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

    /** Hand an uncaught exception to the exception handler. */
    public function handleException(Throwable $exception): void
    {
        self::$reservedMemory = null;

        try {
            $this->handler->handleAndExit($exception);
        } catch (Throwable $fallback) {
            $this->renderFallback($exception, $fallback);
        }
    }

    /** Handle a fatal error that surfaced at shutdown. */
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

    /** Print both errors as plain text when the exception handler itself fails. */
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
