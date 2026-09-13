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
