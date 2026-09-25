<?php

namespace Nitro\Foundation;

/**
 * Discover the service providers of modules under app/Modules.
 *
 * A module's provider is any *ServiceProvider.php file in its directory,
 * addressed as App\Modules\{Module}\{Class}.
 */
class ModuleManifest
{
    /**
     * @param string $modulesPath Absolute path to the app/Modules directory.
     */
    public function __construct(private string $modulesPath) {}

    /**
     * Get the provider classes of every module, skipping any that cannot be autoloaded.
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

                if (class_exists($class)) {
                    $providers[] = $class;
                }
            }
        }

        return $providers;
    }
}
