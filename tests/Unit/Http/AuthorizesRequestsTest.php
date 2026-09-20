<?php

namespace Tests\Unit\Http;

use Nitro\Auth\Access\Gate;
use Nitro\Auth\Exceptions\AuthorizationException;
use Nitro\Container\Container;
use Nitro\Http\Controller\Concerns\AuthorizesRequests;
use Nitro\Http\Controller\Controller;
use PHPUnit\Framework\TestCase;

/**
 * Asking the Gate from inside an action.
 *
 * The Gate answered abilities long before a controller could conveniently ask
 * one — `allows`, `denies`, `check`, policies, before/after callbacks were all
 * there, with no short way to say "stop here if they may not". This is that
 * short way, and nothing more: every decision is still the Gate's.
 */
class AuthorizesRequestsTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(new Container());
        $this->container = Container::getInstance();

        $gate = new Gate($this->container, static fn (): object => (object) ['id' => 7]);

        $gate->define('edit-post', static fn (object $user, object $post): bool => $post->authorId === $user->id);

        $this->container->instance(Gate::class, $gate);
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());

        parent::tearDown();
    }

    private function post(int $authorId): object
    {
        return (object) ['authorId' => $authorId];
    }

    public function test_authorize_passes_silently_when_the_gate_allows(): void
    {
        $controller = new AuthorizedController();

        $controller->edit($this->post(7));

        $this->assertTrue(true, 'authorize() returned without throwing');
    }

    public function test_authorize_stops_the_request_when_the_gate_denies(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This action is unauthorized: edit-post.');

        (new AuthorizedController())->edit($this->post(99));
    }

    public function test_can_and_cannot_answer_without_stopping(): void
    {
        $controller = new AuthorizedController();

        $this->assertTrue($controller->mayEdit($this->post(7)));
        $this->assertFalse($controller->mayEdit($this->post(99)));

        $this->assertFalse($controller->mayNotEdit($this->post(7)));
        $this->assertTrue($controller->mayNotEdit($this->post(99)));
    }

    public function test_authorize_for_user_asks_about_someone_else(): void
    {
        $controller = new AuthorizedController();

        $controller->editAs((object) ['id' => 7], $this->post(7));

        $this->expectException(AuthorizationException::class);

        $controller->editAs((object) ['id' => 8], $this->post(7));
    }
}

class AuthorizedController extends Controller
{
    use AuthorizesRequests;

    public function edit(object $post): void
    {
        $this->authorize('edit-post', $post);
    }

    public function editAs(object $user, object $post): void
    {
        $this->authorizeForUser($user, 'edit-post', $post);
    }

    public function mayEdit(object $post): bool
    {
        return $this->can('edit-post', $post);
    }

    public function mayNotEdit(object $post): bool
    {
        return $this->cannot('edit-post', $post);
    }
}
