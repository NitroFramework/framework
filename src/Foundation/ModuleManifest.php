<?php

namespace Nitro\Foundation;

/**
 * Discovers module service providers under the application's Modules directory.
 *
 * A module is a subdirectory of app/Modules/ whose *ServiceProvider.php file maps,
 * by PSR-4 (App\ → app/), to App\Modules\{Dir}\{Class}. This scan runs in dev;
 * in production `nitro optimize` bakes the discovered provider list into the
 * bootstrap cache, so there is no per-request filesystem scan live.
 */
class ModuleManifest
{
    /**
     * @param string $modulesPath Absolute path to the app/Modules directory.
     */
    public function __construct(private string $modulesPath) {}

    /**
     * Discover the module service-provider class names.
     *
     * @return array<int, class-string>
     */
    public function providers(): array
    {
        if (!is_dir($this->modulesPath)) {
            return [];
        }

        $providers = [];

        foreach (glob($this->modulesPath . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $moduleName = basename($moduleDir);

            foreach (glob($moduleDir . DIRECTORY_SEPARATOR . '*ServiceProvider.php') ?: [] as $providerFile) {
                $class = 'App\\Modules\\' . $moduleName . '\\' . basename($providerFile, '.php');

                // class_exists autoloads via the app's PSR-4 map; a module whose
                // namespace isn't autoloadable is skipped rather than fatal.
                if (class_exists($class)) {
                    $providers[] = $class;
                }
            }
        }

        return $providers;
    }
}
