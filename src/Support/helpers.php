<?php

/**
 * NitroPHP Helper Functions Loader.
 *
 * In production a console command bundles every Helpers/*.php file into a
 * single Helpers/bundle.php. Loading the bundle is one file open + one opcache
 * lookup instead of ~20, which is measurable in the request hot path.
 *
 * When the bundle is absent (dev, fresh checkout) we fall back to loading the
 * individual files in dependency order.
 *
 * Order matters — core helpers (app, config) must load before others.
 *
 * The bundle is only used while it is newer than every file it was built from.
 * Without that check it shadows its own sources: edit a helper, and the change
 * is silently ignored until somebody remembers to re-run optimize — the edit
 * looks applied, the file on disk says so, and the running application
 * disagrees. That cost an afternoon, and the fix costs twenty stat calls.
 */

$bundle = __DIR__ . '/Helpers/bundle.php';

if (is_file($bundle) && nitro_helpers_bundle_is_current($bundle)) {
    require_once $bundle;
    return;
}

/**
 * Whether the bundle is newer than every source it was built from.
 *
 * Skipped only when APP_ENV explicitly says production, where the sources do
 * not change between deploys and the stat calls would be paid on every request
 * for nothing. Explicit, because the default has to be the safe direction:
 * an unset APP_ENV that means "assume production" is how a development box
 * ends up trusting a stale bundle, which is the exact failure this guard was
 * written to stop.
 */
function nitro_helpers_bundle_is_current(string $bundle): bool
{
    $environment = $_ENV['APP_ENV'] ?? getenv('APP_ENV');

    if ($environment === 'production') {
        return true;
    }

    $builtAt = filemtime($bundle);

    foreach (glob(__DIR__ . '/Helpers/*.php') ?: [] as $source) {
        if ($source !== $bundle && filemtime($source) > $builtAt) {
            return false;
        }
    }

    return true;
}

require_once __DIR__ . '/Helpers/app.php';
require_once __DIR__ . '/Helpers/config.php';
require_once __DIR__ . '/Helpers/path.php';
require_once __DIR__ . '/Helpers/array.php';
require_once __DIR__ . '/Helpers/collection.php';
require_once __DIR__ . '/Helpers/conditional.php';
require_once __DIR__ . '/Helpers/debug.php';
require_once __DIR__ . '/Helpers/file.php';
require_once __DIR__ . '/Helpers/http.php';
require_once __DIR__ . '/Helpers/request.php';
require_once __DIR__ . '/Helpers/response.php';
require_once __DIR__ . '/Helpers/security.php';
require_once __DIR__ . '/Helpers/auth.php';
require_once __DIR__ . '/Helpers/session.php';
require_once __DIR__ . '/Helpers/string.php';
require_once __DIR__ . '/Helpers/url.php';
require_once __DIR__ . '/Helpers/utility.php';
require_once __DIR__ . '/Helpers/validation.php';
require_once __DIR__ . '/Helpers/view.php';
require_once __DIR__ . '/Helpers/query.php';
require_once __DIR__ . '/Helpers/cache.php';
require_once __DIR__ . '/Helpers/cookie.php';

require_once __DIR__ . '/Helpers/translation.php';
