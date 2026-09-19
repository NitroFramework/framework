<?php

namespace Tests\Unit\Http;

use Nitro\Foundation\MaintenanceMode;
use Nitro\Http\Middleware\PreventRequestsDuringMaintenance;
use Nitro\Http\Request;
use Nitro\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * The switch has to be wired to something.
 *
 * MaintenanceMode could activate, deactivate, carry a retry window and check a
 * bypass secret long before anything consulted it — so `down` wrote a file and
 * the site kept serving. These cover the wire, not the switch.
 */
class PreventRequestsDuringMaintenanceTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/nitro-down-' . getmypid();
        @unlink($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function maintenance(): MaintenanceMode
    {
        return new MaintenanceMode($this->file);
    }

    private function through(Request $request, ?MaintenanceMode $mode = null): Response
    {
        $middleware = new PreventRequestsDuringMaintenance($mode ?? $this->maintenance());

        return $middleware->handle(
            $request,
            static fn (): Response => new Response('through', 200),
        );
    }

    private function request(string $path = '/', array $cookies = []): Request
    {
        return new Request('GET', $path, [], [], [], [], [], $cookies);
    }

    public function test_a_request_passes_through_when_the_app_is_up(): void
    {
        $response = $this->through($this->request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('through', $response->getContent());
    }

    public function test_a_request_is_refused_with_503_when_the_app_is_down(): void
    {
        $this->maintenance()->activate();

        $this->assertSame(503, $this->through($this->request())->getStatusCode());
    }

    /**
     * The header is the part worth asserting: a 503 with no Retry-After leaves a
     * crawler to guess when to return, which is how a deploy window becomes
     * deindexed pages.
     */
    public function test_retry_after_is_sent_when_one_was_recorded(): void
    {
        $this->maintenance()->activate(['retry' => 90]);

        $this->assertSame('90', $this->through($this->request())->header('Retry-After'));
    }

    public function test_no_retry_after_header_when_none_was_given(): void
    {
        $this->maintenance()->activate();

        $this->assertNull($this->through($this->request())->header('Retry-After'));
    }

    public function test_the_recorded_message_is_rendered_and_escaped(): void
    {
        $this->maintenance()->activate(['message' => 'Back at <14:00>']);

        $content = $this->through($this->request())->getContent();

        $this->assertStringContainsString('Back at &lt;14:00&gt;', $content);
        $this->assertStringNotContainsString('<14:00>', $content);
    }

    /**
     * Visiting the secret sets a cookie and redirects, so the secret leaves the
     * URL bar — and the history, and any referrer header — straight away.
     */
    public function test_the_secret_path_sets_a_bypass_cookie_and_redirects(): void
    {
        $this->maintenance()->activate(['secret' => 'let-me-in']);

        $response = $this->through($this->request('/let-me-in'));

        $this->assertTrue($response->isRedirect('/'));

        $cookies = $response->cookies();
        $this->assertCount(1, $cookies);
        $this->assertSame(PreventRequestsDuringMaintenance::BYPASS_COOKIE, $cookies[0]->name);
        $this->assertSame('let-me-in', $cookies[0]->value);
        $this->assertTrue($cookies[0]->httpOnly);
    }

    public function test_a_valid_bypass_cookie_lets_the_request_through(): void
    {
        $this->maintenance()->activate(['secret' => 'let-me-in']);

        $response = $this->through($this->request('/', [
            PreventRequestsDuringMaintenance::BYPASS_COOKIE => 'let-me-in',
        ]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_a_stale_bypass_cookie_does_not(): void
    {
        $this->maintenance()->activate(['secret' => 'the-new-secret']);

        $response = $this->through($this->request('/', [
            PreventRequestsDuringMaintenance::BYPASS_COOKIE => 'the-old-secret',
        ]));

        $this->assertSame(503, $response->getStatusCode());
    }

    public function test_a_wrong_secret_path_is_just_another_refused_request(): void
    {
        $this->maintenance()->activate(['secret' => 'let-me-in']);

        $this->assertSame(503, $this->through($this->request('/guessing'))->getStatusCode());
    }

    public function test_no_secret_configured_means_no_path_bypasses(): void
    {
        $this->maintenance()->activate();

        $this->assertSame(503, $this->through($this->request('/anything'))->getStatusCode());
    }
}
