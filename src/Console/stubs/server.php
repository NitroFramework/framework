<?php

/**
 * Development-server router for `php nitro serve` (php -S).
 *
 * The built-in server calls this for every request. Existing files under the
 * public/ document root are served verbatim (return false); everything else is
 * routed through the front controller, giving pretty-URL routing in dev without
 * Apache/nginx. The public path is passed in via NITRO_PUBLIC_PATH.
 */

$publicPath = $_SERVER['NITRO_PUBLIC_PATH'] ?? getcwd() . '/public';
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

$requested = $publicPath . $uri;
if ($uri !== '/' && is_file($requested)) {
    return false; // let the built-in server stream the static asset as-is
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicPath . '/index.php';

require $publicPath . '/index.php';
