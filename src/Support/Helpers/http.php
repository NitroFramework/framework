<?php

/**
 * HTTP helper functions.
 *
 * NOTE: the former not_found / forbidden / unauthorized / server_error /
 * bad_request shorthand helpers were removed in favor of calling
 * `abort($code, $message)` directly (Laravel-style).
 *
 * The is_ajax / is_post / is_get / user_agent / client_ip globals were also
 * removed; use the Request instance instead:
 *
 *   request()->ajax()
 *   request()->isMethod('POST')
 *   request()->header('user-agent')
 *   request()->ip()
 *
 * This file is intentionally minimal; everything HTTP-related is exposed via
 * the Request object, the abort() helper, or the Response factories.
 */
