<?php

namespace Nitro\Testing;

use Nitro\Http\RedirectResponse;
use Nitro\Http\Response;
use PHPUnit\Framework\Assert;

/**
 * A response, wrapped so a test can make assertions about it.
 *
 * Every assertion returns $this so they chain, and every failure message says
 * what the response actually was — a bare "failed asserting 500 is 200" sends
 * you to the logs, so the body is included when a test expected success and got
 * an error instead.
 */
class TestResponse
{
    public function __construct(public Response $baseResponse) {}

    public function status(): int
    {
        return $this->baseResponse->getStatusCode();
    }

    public function content(): string
    {
        return (string) $this->baseResponse->getContent();
    }

    public function headers(): array
    {
        return $this->baseResponse->headers();
    }

    public function header(string $name): ?string
    {
        foreach ($this->baseResponse->headers() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    // ─── Status ───────────────────────────────────────────

    public function assertStatus(int $expected): static
    {
        Assert::assertSame(
            $expected,
            $this->status(),
            "Expected status {$expected}, got {$this->status()}." . $this->bodyHint()
        );

        return $this;
    }

    public function assertOk(): static
    {
        return $this->assertStatus(200);
    }

    public function assertNotFound(): static
    {
        return $this->assertStatus(404);
    }

    public function assertForbidden(): static
    {
        return $this->assertStatus(403);
    }

    public function assertUnauthorized(): static
    {
        return $this->assertStatus(401);
    }

    /** Any 2xx or 3xx. What "the page did not break" actually means. */
    public function assertSuccessful(): static
    {
        Assert::assertTrue(
            $this->status() >= 200 && $this->status() < 400,
            "Expected a successful status, got {$this->status()}." . $this->bodyHint()
        );

        return $this;
    }

    // ─── Redirects ────────────────────────────────────────

    public function assertRedirect(?string $to = null): static
    {
        Assert::assertTrue(
            $this->status() >= 300 && $this->status() < 400,
            "Expected a redirect, got {$this->status()}." . $this->bodyHint()
        );

        if ($to !== null) {
            Assert::assertSame($to, $this->header('Location'), 'Redirected somewhere else.');
        }

        return $this;
    }

    // ─── Content ──────────────────────────────────────────

    /**
     * $escaped defaults true because the thing you are usually checking for is a
     * name or a title that Blade has escaped on the way out; searching the raw
     * string for "Ellie O'Brien" would not find "Ellie O&#039;Brien" and the
     * failure looks like the page is missing the record.
     */
    public function assertSee(string $value, bool $escaped = true): static
    {
        $needle = $escaped ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;

        Assert::assertStringContainsString($needle, $this->content(), "Did not see [{$value}] in the response.");

        return $this;
    }

    public function assertDontSee(string $value, bool $escaped = true): static
    {
        $needle = $escaped ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;

        Assert::assertStringNotContainsString($needle, $this->content(), "Unexpectedly saw [{$value}] in the response.");

        return $this;
    }

    /** Several strings, in the order given — a list really is in that order. */
    public function assertSeeInOrder(array $values, bool $escaped = true): static
    {
        $content = $this->content();
        $offset = 0;

        foreach ($values as $value) {
            $needle = $escaped ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
            $position = strpos($content, $needle, $offset);

            Assert::assertNotFalse($position, "Did not see [{$value}] after the previous value.");

            $offset = $position + strlen($needle);
        }

        return $this;
    }

    // ─── JSON ─────────────────────────────────────────────

    public function json(?string $key = null): mixed
    {
        $decoded = json_decode($this->content(), true);

        Assert::assertIsArray($decoded, 'The response was not valid JSON.' . $this->bodyHint());

        if ($key === null) {
            return $decoded;
        }

        $value = $decoded;
        foreach (explode('.', $key) as $segment) {
            Assert::assertArrayHasKey($segment, $value, "The JSON has no key [{$key}].");
            $value = $value[$segment];
        }

        return $value;
    }

    public function assertJson(array $expected): static
    {
        $actual = $this->json();

        foreach ($expected as $key => $value) {
            Assert::assertArrayHasKey($key, $actual, "The JSON has no key [{$key}].");
            Assert::assertSame($value, $actual[$key], "The JSON key [{$key}] does not match.");
        }

        return $this;
    }

    /**
     * Assert one value, addressed with dots.
     *
     * The one to reach for on a nested payload: assertJson() compares top-level
     * keys, so checking data.user.name with it means writing out the whole
     * enclosing structure.
     */
    public function assertJsonPath(string $path, mixed $expected): static
    {
        Assert::assertSame($expected, $this->json($path), "The JSON at [{$path}] does not match.");

        return $this;
    }

    /** Assert how many entries are at a path, or at the root. */
    public function assertJsonCount(int $expected, ?string $path = null): static
    {
        $value = $path === null ? $this->json() : $this->json($path);

        Assert::assertIsArray($value, 'The JSON at [' . ($path ?? 'root') . '] is not a list.');
        Assert::assertCount($expected, $value, 'The JSON at [' . ($path ?? 'root') . '] has the wrong number of entries.');

        return $this;
    }

    /**
     * Assert the shape, without caring about the values.
     *
     * A list is described by its first entry: ['data' => [['id', 'name']]]
     * checks every entry of data has an id and a name.
     *
     * @param array<mixed> $structure
     * @param array<mixed>|null $actual
     */
    public function assertJsonStructure(array $structure, ?array $actual = null): static
    {
        $actual ??= $this->json();

        foreach ($structure as $key => $value) {
            if ($key === '*' || (is_int($key) && $value === '*')) {
                Assert::assertIsArray($actual, 'Expected a list.');

                continue;
            }

            if (is_int($key)) {
                Assert::assertArrayHasKey($value, $actual, "The JSON has no key [{$value}].");

                continue;
            }

            Assert::assertArrayHasKey($key, $actual, "The JSON has no key [{$key}].");

            // A list is described once and checked against every entry.
            if (is_array($value) && array_is_list($value) && count($value) === 1 && is_array($value[0])) {
                foreach ($actual[$key] as $entry) {
                    $this->assertJsonStructure($value[0], $entry);
                }

                continue;
            }

            if (is_array($value)) {
                $this->assertJsonStructure($value, $actual[$key]);
            }
        }

        return $this;
    }

    /** Assert the JSON does not carry these values at the top level. */
    public function assertJsonMissing(array $unexpected): static
    {
        $actual = $this->json();

        foreach ($unexpected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                continue;
            }

            Assert::assertNotSame($value, $actual[$key], "The JSON key [{$key}] was not expected to match.");
        }

        return $this;
    }

    // ─── Headers ──────────────────────────────────────────

    public function assertHeader(string $name, ?string $expected = null): static
    {
        $actual = $this->header($name);

        Assert::assertNotNull($actual, "The response has no [{$name}] header.");

        if ($expected !== null) {
            Assert::assertSame($expected, $actual, "The [{$name}] header does not match.");
        }

        return $this;
    }

    public function assertHeaderMissing(string $name): static
    {
        Assert::assertNull($this->header($name), "The response carries an unexpected [{$name}] header.");

        return $this;
    }

    /** Assert where a redirect points, without asserting the status. */
    public function assertLocation(string $expected): static
    {
        Assert::assertSame($expected, $this->header('Location'), 'The response points somewhere else.');

        return $this;
    }

    /**
     * Assert the response offers a file to save.
     *
     * @param string|null $filename The name it is offered under, when it matters.
     */
    public function assertDownload(?string $filename = null): static
    {
        $disposition = (string) $this->header('Content-Disposition');

        Assert::assertStringContainsString(
            'attachment',
            $disposition,
            'The response is not a download.' . $this->bodyHint()
        );

        if ($filename !== null) {
            Assert::assertStringContainsString(
                $filename,
                $disposition,
                "The download is not named [{$filename}]."
            );
        }

        return $this;
    }

    // ─── Cookies ──────────────────────────────────────────

    public function assertCookie(string $name, ?string $expected = null): static
    {
        $cookie = $this->cookie($name);

        Assert::assertNotNull($cookie, "The response does not set a [{$name}] cookie.");

        if ($expected !== null) {
            Assert::assertSame($expected, $cookie, "The [{$name}] cookie does not match.");
        }

        return $this;
    }

    public function assertCookieMissing(string $name): static
    {
        Assert::assertNull($this->cookie($name), "The response sets an unexpected [{$name}] cookie.");

        return $this;
    }

    /** The value of a cookie the response sets, or null. */
    public function cookie(string $name): ?string
    {
        foreach ($this->baseResponse->cookies() as $cookie) {
            $cookieName = is_object($cookie) && property_exists($cookie, 'name')
                ? $cookie->name
                : (is_object($cookie) && method_exists($cookie, 'getName') ? $cookie->getName() : null);

            if ($cookieName !== $name) {
                continue;
            }

            return is_object($cookie) && method_exists($cookie, 'getValue')
                ? (string) $cookie->getValue()
                : (string) ($cookie->value ?? '');
        }

        return null;
    }

    // ─── Session ──────────────────────────────────────────

    /**
     * Assert the session holds a value.
     *
     * The usual reason: a form posted, redirected, and put something in the
     * session on the way — which the response body cannot show, because the
     * body is a redirect.
     *
     * @param array<string, mixed>|string $key A map asserts several at once.
     */
    public function assertSessionHas(array|string $key, mixed $expected = null): static
    {
        $session = $this->session();

        foreach (is_array($key) ? $key : [$key => $expected] as $name => $value) {
            if (is_int($name)) {
                $name = $value;
                $value = null;
            }

            Assert::assertTrue($session->has($name), "The session has no [{$name}].");

            if ($value !== null) {
                Assert::assertSame($value, $session->get($name), "The session value for [{$name}] does not match.");
            }
        }

        return $this;
    }

    public function assertSessionMissing(string $key): static
    {
        Assert::assertFalse($this->session()->has($key), "The session unexpectedly holds [{$key}].");

        return $this;
    }

    /**
     * Assert validation failed, optionally for particular fields.
     *
     * @param array<int, string>|string $keys
     */
    public function assertSessionHasErrors(array|string $keys = []): static
    {
        $errors = (array) $this->session()->get('errors', []);

        Assert::assertNotEmpty($errors, 'The session holds no validation errors.');

        foreach ((array) $keys as $key) {
            Assert::assertArrayHasKey($key, $errors, "The session holds no error for [{$key}].");
        }

        return $this;
    }

    public function assertSessionHasNoErrors(): static
    {
        $errors = (array) $this->session()->get('errors', []);

        Assert::assertEmpty(
            $errors,
            'The session holds validation errors: ' . implode(', ', array_keys($errors)) . '.'
        );

        return $this;
    }

    /** The application's session store. */
    protected function session(): object
    {
        $container = \Nitro\Container\Container::getInstance();

        Assert::assertTrue(
            $container->has('session'),
            'This application has no session, so there is nothing to assert about one.'
        );

        return $container->resolve('session');
    }

    // ─── Views ────────────────────────────────────────────

    /**
     * Assert which view rendered the response.
     *
     * The outermost one — a page renders partials inside itself, and the
     * question being asked is which page this is.
     */
    public function assertViewIs(string $expected): static
    {
        $rendered = $this->renderedViews();

        Assert::assertNotEmpty($rendered, 'The response did not render a view.');
        Assert::assertSame($expected, $rendered[0]['name'], 'A different view rendered this response.');

        return $this;
    }

    /**
     * Assert the view was given a value.
     *
     * @param array<string, mixed>|string $key A map asserts several at once.
     */
    public function assertViewHas(array|string $key, mixed $expected = null): static
    {
        $rendered = $this->renderedViews();

        Assert::assertNotEmpty($rendered, 'The response did not render a view.');

        $data = $rendered[0]['data'];

        foreach (is_array($key) ? $key : [$key => $expected] as $name => $value) {
            if (is_int($name)) {
                $name = $value;
                $value = null;
            }

            Assert::assertArrayHasKey($name, $data, "The view was not given [{$name}].");

            if ($value !== null) {
                Assert::assertEquals($value, $data[$name], "The view's [{$name}] does not match.");
            }
        }

        return $this;
    }

    public function assertViewMissing(string $key): static
    {
        $rendered = $this->renderedViews();

        Assert::assertNotEmpty($rendered, 'The response did not render a view.');
        Assert::assertArrayNotHasKey($key, $rendered[0]['data'], "The view was unexpectedly given [{$key}].");

        return $this;
    }

    /**
     * What the view factory rendered for this request, outermost first.
     *
     * @return array<int, array{name: string, data: array<string, mixed>}>
     */
    protected function renderedViews(): array
    {
        $container = \Nitro\Container\Container::getInstance();

        if (! $container->has(\Nitro\View\Factory::class)) {
            return [];
        }

        return $container->resolve(\Nitro\View\Factory::class)->rendered();
    }

    /**
     * The first 500 characters of the body, for a failure message. Worth the
     * noise: an unexpected 500 is nearly always explained by its own output.
     */
    private function bodyHint(): string
    {
        $body = trim(strip_tags($this->content()));

        if ($body === '') {
            return '';
        }

        return "\nResponse body: " . mb_substr(preg_replace('/\s+/', ' ', $body), 0, 500);
    }
}
