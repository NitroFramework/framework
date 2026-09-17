<?php

namespace Tests\Unit\Architecture;

use Nitro\Http\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Architectural guard: the responseReady seam has exactly one caller.
 *
 * Hooks on that seam do work a response is incomplete without — the session
 * provider emits its Set-Cookie header from one — so a return path that skips
 * them yields a response that looks correct and is missing a header. Nothing
 * fails, which is why this is a structural guard rather than a behavioural one:
 * KernelExitSeamTest covers the exits that exist today, but neither it nor any
 * assertion about status or body would notice a sixth exit added tomorrow.
 *
 * The invariant: Kernel::handle() returns in one place, through finish(), and
 * finish() is the only method that touches $responseReadyHooks. Adding an exit
 * therefore means returning through the funnel, not remembering a hook call.
 *
 * Uses token analysis rather than grep so the phrases in comments and docblocks
 * above do not match themselves.
 */
class ResponseSeamGuardTest extends TestCase
{
    /**
     * The method permitted to run the responseReady hooks.
     */
    private const SEAM_OWNER = 'finish';

    public function test_handle_returns_only_through_the_funnel(): void
    {
        $returns = $this->returnedExpressionsOf('handle');

        $this->assertCount(
            1,
            $returns,
            "Kernel::handle() must have exactly one return so that every exit passes the\n"
            . "responseReady seam. Found " . count($returns) . ":\n  " . implode("\n  ", $returns)
        );

        $this->assertStringContainsString(
            '$this->' . self::SEAM_OWNER . '(',
            $returns[0],
            'Kernel::handle() returns without going through ' . self::SEAM_OWNER . '().'
        );
    }

    public function test_only_the_funnel_runs_the_response_ready_hooks(): void
    {
        $offenders = [];

        foreach ((new \ReflectionClass(Kernel::class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== Kernel::class) {
                continue;
            }

            if (in_array($method->getName(), [self::SEAM_OWNER, 'getLifecycleHooks', 'responseReady'], true)) {
                continue;
            }

            if (str_contains($this->bodyOf($method->getName()), 'responseReadyHooks')) {
                $offenders[] = 'Kernel::' . $method->getName() . '()';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Only Kernel::" . self::SEAM_OWNER . "() may run the responseReady hooks — every other exit\n"
            . "must return through it, or it becomes possible to add a return that\n"
            . "silently skips the seam. Offending methods:\n  " . implode("\n  ", $offenders)
        );
    }

    public function test_the_funnel_exists_and_runs_the_hooks(): void
    {
        $this->assertTrue(
            method_exists(Kernel::class, self::SEAM_OWNER),
            'Kernel::' . self::SEAM_OWNER . '() is gone; this guard is asserting nothing.'
        );

        $this->assertStringContainsString(
            'responseReadyHooks',
            $this->bodyOf(self::SEAM_OWNER),
            'Kernel::' . self::SEAM_OWNER . '() no longer runs the responseReady hooks.'
        );
    }

    // ─── Source inspection ──────────────────────────────────────────────────

    /**
     * The source of one Kernel method, docblock excluded.
     */
    private function bodyOf(string $method): string
    {
        $reflection = new ReflectionMethod(Kernel::class, $method);
        $lines = file((string) $reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }

    /**
     * The expression returned by each `return` in a method, as written.
     *
     * Token-based, so a `return` inside a string or a comment is not counted —
     * and neither are the ones described in this file's own docblocks.
     *
     * @return array<int, string>
     */
    private function returnedExpressionsOf(string $method): array
    {
        $tokens = token_get_all('<?php ' . $this->bodyOf($method));

        $expressions = [];
        $collecting = false;
        $current = '';

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_RETURN) {
                $collecting = true;
                $current = '';
                continue;
            }

            if (! $collecting) {
                continue;
            }

            $text = is_array($token) ? $token[1] : $token;

            if ($text === ';') {
                $expressions[] = trim($current);
                $collecting = false;
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $current .= $text;
        }

        return $expressions;
    }
}
