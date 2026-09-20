<?php

namespace Tests\Unit\Http;

use BadMethodCallException;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Foundation\Application;
use Nitro\Http\Controller\Concerns\RespondsWithJson;
use Nitro\Http\Controller\Controller;
use Nitro\Http\Controller\HasMiddleware;
use Nitro\Http\Controller\Middleware;
use Nitro\Http\Kernel;
use Nitro\Routing\Route;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * What a controller inherits, and what it has to ask for.
 *
 * The base used to pull in five traits and a constructor, which meant every
 * controller ever written carried twenty-five methods and broke if it declared
 * its own constructor without calling the parent's. Everything those methods
 * did is reachable as a helper or a facade, so the base now carries the two
 * things only a base can: how an action is invoked, and what a missing method
 * does.
 */
class ControllerShapeTest extends TestCase
{
    // ─── The base itself ──────────────────────────────────

    /** The whole point: a controller is not handed an API it did not ask for. */
    public function test_a_controller_inherits_almost_nothing(): void
    {
        $inherited = array_map(
            static fn (ReflectionMethod $method): string => $method->name,
            (new ReflectionClass(PlainController::class))->getMethods()
        );

        $inherited = array_values(array_diff($inherited, ['show', 'withDependency']));

        sort($inherited);

        $this->assertSame(['__call', 'callAction'], $inherited);
    }

    /** No base constructor is what removes the uninitialised-property trap. */
    public function test_the_base_declares_no_constructor(): void
    {
        $this->assertNull((new ReflectionClass(Controller::class))->getConstructor());
    }

    public function test_a_controller_may_declare_its_own_constructor(): void
    {
        $this->assertSame('given', (new ConstructedController('given'))->show());
    }

    // ─── Missing methods ──────────────────────────────────

    /**
     * A typo used to be answered by the container — "Class [jsonn] does not
     * exist" — which points at the wrong thing entirely.
     */
    public function test_calling_a_method_that_is_not_there_says_so(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage(PlainController::class . '::nope() does not exist.');

        (new PlainController())->nope();
    }

    // ─── callAction ───────────────────────────────────────

    public function test_call_action_spreads_the_arguments_it_is_given(): void
    {
        $this->assertSame('a:b', (new PlainController())->callAction('withDependency', ['a', 'b']));
    }

    /** The seam: a controller can wrap every one of its own actions. */
    public function test_call_action_can_be_overridden_to_wrap_an_action(): void
    {
        $this->assertSame('[a:b]', (new WrappingController())->callAction('withDependency', ['a', 'b']));
    }

    // ─── Controller middleware ────────────────────────────

    private function kernel(): Kernel
    {
        $application = $this->createMock(Application::class);
        $application->method('getContainer')->willReturn($this->createMock(ContainerInterface::class));

        return new Kernel(
            $application,
            $this->createMock(Router::class),
            $this->createMock(RouteDispatcher::class),
        );
    }

    private function gather(string $controller, string $method): array
    {
        return (new ReflectionMethod(Kernel::class, 'gatherMiddleware'))->invoke(
            $this->kernel(),
            Route::controller($controller, $method)
        );
    }

    public function test_a_controller_declares_middleware_for_every_action(): void
    {
        $this->assertContains('auth', $this->gather(GuardedController::class, 'index'));
        $this->assertContains('auth', $this->gather(GuardedController::class, 'show'));
    }

    public function test_except_drops_it_for_the_named_actions(): void
    {
        $this->assertContains('verified', $this->gather(GuardedController::class, 'index'));
        $this->assertNotContains('verified', $this->gather(GuardedController::class, 'show'));
    }

    public function test_only_limits_it_to_the_named_actions(): void
    {
        $this->assertContains('signed', $this->gather(GuardedController::class, 'show'));
        $this->assertNotContains('signed', $this->gather(GuardedController::class, 'index'));
    }

    public function test_a_controller_declaring_none_adds_none(): void
    {
        $this->assertSame([], $this->gather(PlainController::class, 'show'));
    }

    /** Read off the class, so nothing has to be constructed to know the stack. */
    public function test_the_controller_is_never_constructed_to_read_its_middleware(): void
    {
        CountsConstructions::$built = 0;

        $this->gather(CountsConstructions::class, 'show');

        $this->assertSame(0, CountsConstructions::$built);
    }

    // ─── Middleware value object ──────────────────────────

    public function test_middleware_narrows_fluently(): void
    {
        $middleware = Middleware::for('auth')->except('show');

        $this->assertTrue($middleware->appliesTo('index'));
        $this->assertFalse($middleware->appliesTo('show'));
    }

    // ─── The JSON envelope ────────────────────────────────

    public function test_the_json_envelope_is_opt_in_and_keeps_its_shape(): void
    {
        $controller = new JsonController();

        $this->assertSame(
            ['success' => true, 'message' => 'Saved', 'data' => ['id' => 1]],
            json_decode($controller->ok()->getContent(), true)
        );

        $failure = $controller->bad();

        $this->assertSame(422, $failure->getStatusCode());
        $this->assertSame(
            ['success' => false, 'message' => 'Nope', 'errors' => ['name' => 'required']],
            json_decode($failure->getContent(), true)
        );
    }
}

class PlainController extends Controller
{
    public function show(): string
    {
        return 'shown';
    }

    public function withDependency(string $first, string $second): string
    {
        return "{$first}:{$second}";
    }
}

class ConstructedController extends Controller
{
    public function __construct(private readonly string $label)
    {
    }

    public function show(): string
    {
        return $this->label;
    }
}

class WrappingController extends Controller
{
    public function callAction(string $method, array $arguments): mixed
    {
        return '[' . parent::callAction($method, $arguments) . ']';
    }

    public function withDependency(string $first, string $second): string
    {
        return "{$first}:{$second}";
    }
}

class GuardedController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            'auth',
            new Middleware('verified', except: ['show']),
            Middleware::for('signed')->only('show'),
        ];
    }

    public function index(): string
    {
        return '';
    }

    public function show(): string
    {
        return '';
    }
}

class CountsConstructions extends Controller implements HasMiddleware
{
    public static int $built = 0;

    public function __construct()
    {
        self::$built++;
    }

    public static function middleware(): array
    {
        return ['auth'];
    }

    public function show(): string
    {
        return '';
    }
}

class JsonController extends Controller
{
    use RespondsWithJson;

    public function ok(): \Nitro\Http\Response
    {
        return $this->success(['id' => 1], 'Saved');
    }

    public function bad(): \Nitro\Http\Response
    {
        return $this->error('Nope', 422, ['name' => 'required']);
    }
}
