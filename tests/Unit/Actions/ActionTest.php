<?php

namespace Tests\Unit\Actions;

use Nitro\Actions\Action;
use Nitro\Container\Container;
use Nitro\Exceptions\HttpException;
use Nitro\Http\Request;
use Nitro\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class SumAction extends Action
{
    public function handle(int $a, int $b): int
    {
        return $a + $b;
    }
}

class CreateThing extends Action
{
    public function rules(): array
    {
        return ['name' => 'required'];
    }

    public function handle(Request $request): array
    {
        return ['name' => $request->input('name'), 'validated' => $this->validated()];
    }
}

class ForbiddenAction extends Action
{
    public function authorize(): bool
    {
        return false;
    }

    public function handle(): string
    {
        return 'should not run';
    }
}

class PrefersAsController extends Action
{
    public function handle(): string
    {
        return 'from-handle';
    }

    public function asController(Request $request): string
    {
        return 'from-asController';
    }
}

class ShowThing extends Action
{
    public function handle(int $id): array
    {
        return ['id' => $id];
    }
}

class NegotiatedAction extends Action
{
    public function handle(): array
    {
        return ['x' => 1];
    }

    public function jsonResponse(mixed $response, Request $request): string
    {
        return 'JSON:' . json_encode($response);
    }

    public function htmlResponse(mixed $response, Request $request): string
    {
        return 'HTML';
    }
}

class ActionTest extends TestCase
{
    private function invoke(Action $action, Request $request, array $params = []): mixed
    {
        $c = Container::getInstance();
        $c->instance('request', $request);
        $c->instance(Request::class, $request);

        return $action->runAsController($request, $params, $c);
    }

    // ─── Object mode ────────────────────────────────────────────────────────

    public function test_run_executes_handle(): void
    {
        $this->assertSame(5, SumAction::run(2, 3));
    }

    public function test_make_returns_a_resolved_instance(): void
    {
        $this->assertInstanceOf(SumAction::class, SumAction::make());
    }

    public function test_run_if_gates_execution(): void
    {
        $this->assertSame(7, SumAction::runIf(true, 3, 4));
        $this->assertNull(SumAction::runIf(false, 3, 4));
    }

    // ─── Controller pipeline ─────────────────────────────────────────────────

    public function test_validation_passes_and_exposes_validated_input(): void
    {
        $request = new Request('POST', '/things', [], [], ['name' => 'Widget', 'extra' => 'x']);
        $result  = $this->invoke(new CreateThing(), $request);

        $this->assertSame('Widget', $result['name']);
        // validated() is only the fields named in rules().
        $this->assertSame(['name' => 'Widget'], $result['validated']);
    }

    public function test_validation_failure_throws(): void
    {
        $this->expectException(ValidationException::class);

        $request = new Request('POST', '/things', [], [], []); // missing required 'name'
        $this->invoke(new CreateThing(), $request);
    }

    public function test_authorize_false_throws_403(): void
    {
        $request = new Request('POST', '/x');

        try {
            $this->invoke(new ForbiddenAction(), $request);
            $this->fail('Expected HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_as_controller_is_preferred_over_handle(): void
    {
        $request = new Request('GET', '/x');
        $this->assertSame('from-asController', $this->invoke(new PrefersAsController(), $request));
    }

    public function test_route_parameters_are_passed_to_handle(): void
    {
        $request = new Request('GET', '/things/9');
        $result  = $this->invoke(new ShowThing(), $request, ['id' => 9]);

        $this->assertSame(['id' => 9], $result);
    }

    // ─── Response negotiation ────────────────────────────────────────────────

    public function test_json_response_used_when_client_expects_json(): void
    {
        // Request stores headers lowercase-keyed (the request pipeline normalizes them).
        $request = new Request('GET', '/x', ['accept' => 'application/json']);
        $this->assertSame('JSON:{"x":1}', $this->invoke(new NegotiatedAction(), $request));
    }

    public function test_html_response_used_otherwise(): void
    {
        $request = new Request('GET', '/x', ['accept' => 'text/html']);
        $this->assertSame('HTML', $this->invoke(new NegotiatedAction(), $request));
    }
}
