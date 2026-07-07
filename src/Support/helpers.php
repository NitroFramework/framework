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
 */

$bundle = __DIR__ . '/Helpers/bundle.php';
if (is_file($bundle)) {
    require_once $bundle;
    return;
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
