<?php

namespace Nitro\Htmx\Support;

use Nitro\Container\Container;
use Nitro\Http\Request;

/**
 * Handles all security checks for incoming HTMX requests.
 *
 * Three responsibilities:
 *   1. CSRF token verification on non-GET requests
 *   2. HX-Request header validation (prevents direct browser/curl access)
 *   3. Decryption of obfuscated hx-vals payload (_t parameter)
 */
class RequestGuard
{
    public function __construct(
        private Container $container,
        private bool $csrfEnabled = true,
        private bool $checkHxHeader = true,
    ) {}

    /**
     * Run all applicable security checks on the request.
     *
     * CSRF is checked on non-GET requests when enabled.
     * Encrypted hx-vals are always unwrapped if present.
     * HX-Request header is NOT checked here — it depends on context
     * (page requests skip it), so the kernel calls assertHtmx() separately.
     *
     * @param  Request $request  The incoming HTTP request
     * @return void
     */
    public function guard(Request $request): void
    {
        // Verify CSRF token on state-changing requests (POST, PUT, DELETE, etc.)
        if ($request->method() !== 'GET' && $this->csrfEnabled) {
            $this->verifyCsrf($request);
        }

        // Decrypt any obfuscated hx-vals payload and merge back into request
        $this->unwrapObfuscatedData($request);
    }

    /**
     * Assert that the request was made by HTMX (has HX-Request: true header).
     *
     * This is a convenience guard to prevent direct browser access to HTMX
     * endpoints. It is NOT a security guarantee — the header is trivially
     * spoofable. Use CSRF for real protection.
     *
     * Called separately from guard() because page requests skip this check.
     *
     * @param  Request $request  The incoming HTTP request
     * @return void
     *
     * @throws \RuntimeException via abort() if the header is missing
     */
    public function assertHtmx(Request $request): void
    {
        if ($this->checkHxHeader && $request->header('HX-Request') !== 'true') {
            abort(403, 'Direct access not allowed.');
        }
    }

//     public function assertHtmx(Request $request): void
// {
//     if (!$this->checkHxHeader) {
//         return;
//     }

//     $isHtmx  = $request->header('HX-Request') === 'true';
//     $isNitro = $request->header('X-NX-Request') === 'true';

//     if (!$isHtmx && !$isNitro) {
//         abort(403, 'Direct access not allowed.');
//     }
// }

    /**
     * Verify that the CSRF token in the request matches the session token.
     *
     * Checks two locations:
     *   1. Body parameter: _csrf (from hidden form fields)
     *   2. Header: X-CSRF-Token (from HTMX's hx-headers or meta tag config)
     *
     * @param  Request $request  The incoming HTTP request
     * @return void
     *
     * @throws \RuntimeException via abort(419) on mismatch
     */
    private function verifyCsrf(Request $request): void
    {
        $token = $request->post('_csrf')
            ?? $request->header('X-CSRF-Token');

        // Constant-time compare so the response time can't leak how many leading
        // bytes of the token were guessed correctly. Read the session token raw
        // (don't mint one here — verification must never create a token).
        $expected = $_SESSION['_csrf'] ?? '';

        if (!is_string($token) || $token === '' || !hash_equals($expected, $token)) {
            abort(419, 'CSRF token mismatch.');
        }
    }

    /**
     * Detect and decrypt obfuscated hx-vals payload.
     *
     * When HxHelper compiles a component tag with encrypted vals, it wraps
     * them into a single '_t' parameter. This method detects that parameter,
     * decrypts the payload via HxEncryptor, and merges the original key-value
     * pairs back into the request so the action can access them normally.
     *
     * @param  Request $request  The incoming HTTP request (modified in place)
     * @return void
     *
     * @throws \RuntimeException via abort(400) if decryption fails
     */
    private function unwrapObfuscatedData(Request $request): void
    {
        $token = $request->get('_t') ?? $request->post('_t');

        if ($token) {
            try {
                $encryptor = $this->container->make(HxEncryptor::class);
                $decryptedData = $encryptor->decrypt($token);

                if (!empty($decryptedData)) {
                    $request->merge($decryptedData);
                }
            } catch (\Exception $e) {
                abort(400, 'Invalid security token.');
            }
        }
    }
}