<?php

namespace Nitro\Facades;

/**
 * MaintenanceMode facade — whether the application is down.
 *
 *   MaintenanceMode::activate(['retry' => 60]);
 *   MaintenanceMode::deactivate();
 *
 * @method static bool active()
 * @method static void activate(array $payload = [])
 * @method static void deactivate()
 * @method static array data()
 * @method static int|null retryAfter()
 * @method static bool bypassedBy(?string $secret)
 */
class MaintenanceMode extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'maintenance';
    }
}
