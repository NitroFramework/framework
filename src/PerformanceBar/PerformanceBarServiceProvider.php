<?php

namespace Nitro\PerformanceBar;

use Nitro\Foundation\Http\Kernel;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\Http\Request;
use Throwable;

/**
 * Registers the performance bar's response injection as a Kernel responseReady
 * hook, gated on PerformanceBar::isAvailable() — a single bool check per request
 * when the bar is disabled.
 */
class PerformanceBarServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->container->make(Kernel::class)->responseReady(
            static function (Request $request, $response): void {
                if (!PerformanceBar::isAvailable()) {
                    return;
                }

                try {
                    PerformanceBar::getInstance()->inject($response);
                } catch (Throwable $e) {
                    // Don't let the perf bar kill the response, but surface the
                    // failure in logs so silent breakage doesn't fester.
                    error_log(
                        '[PerformanceBar] inject() failed: '
                        . $e::class . ': ' . $e->getMessage()
                        . ' at ' . $e->getFile() . ':' . $e->getLine()
                    );
                }
            }
        );
    }
}
