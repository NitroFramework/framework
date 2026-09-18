<?php

namespace Nitro\Log\Handlers;

/**
 * Hands the line to PHP's own error_log().
 *
 * Where the line ends up is the SAPI's business — Apache's error log, the
 * FPM log, or stderr — which is what makes this useful when the platform
 * already collects PHP's output and you would rather not name a file.
 */
class ErrorLogHandler implements Handler
{
    public function write(string $level, string $message, array $context = []): void
    {
        $suffix = $context === [] ? '' : ' ' . json_encode($context);

        error_log(strtoupper($level) . ": {$message}{$suffix}");
    }
}
