<?php

namespace Nitro\Broadcasting;

use Nitro\Broadcasting\Drivers\PusherBroadcaster;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Answers the client asking whether it may listen on a channel.
 *
 * A private or presence channel is only private because the socket server
 * refuses to subscribe anyone the application has not vouched for. The client
 * posts the channel it wants and its socket id; this decides, using the
 * callbacks registered with {@see BroadcastManager::channel()}, and signs the
 * answer.
 *
 * Without this endpoint those callbacks were unreachable: the authorisation
 * logic existed and nothing could ask it anything, so a private channel was
 * private in name only — which is to say a client could not join it at all.
 */
class BroadcastController
{
    public function __construct(
        protected BroadcastManager $broadcast,
    ) {}

    public function __invoke(Request $request): Response
    {
        $channel = (string) ($request->input('channel_name') ?? '');
        $socketId = (string) ($request->input('socket_id') ?? '');

        if ($channel === '') {
            return Response::json(['message' => 'No channel was named.'], 422);
        }

        $user = $this->userFor($request);

        // A public channel needs no permission; anything else needs a user.
        if (! $this->isGuarded($channel)) {
            return Response::json(['message' => 'Public channels need no authorisation.'], 403);
        }

        if ($user === null) {
            return Response::json(['message' => 'Unauthenticated.'], 403);
        }

        $result = $this->broadcast->authorise($user, $channel);

        if ($result === false) {
            return Response::json(['message' => 'Forbidden.'], 403);
        }

        return Response::json($this->payloadFor($channel, $socketId, $user, $result));
    }

    /**
     * The signed answer the client hands to the socket server.
     *
     * A presence channel carries the member's identity as well, which is what
     * lets the other subscribers see who is there. A driver that does not sign
     * — log, null, redis behind your own relay — answers with the same shape
     * minus the signature, since there is nothing to verify against.
     *
     * @param array<string, mixed>|bool $result What the channel callback returned.
     * @return array<string, mixed>
     */
    protected function payloadFor(string $channel, string $socketId, mixed $user, mixed $result): array
    {
        $driver = $this->broadcast->connection();

        $isPresence = str_starts_with($channel, 'presence-') || str_starts_with($channel, 'presence.');

        $memberData = $isPresence
            ? [
                'user_id' => $this->identifierFor($user),
                'user_info' => is_array($result) ? $result : [],
            ]
            : null;

        if ($driver instanceof PusherBroadcaster && $socketId !== '') {
            return $driver->authFor($socketId, $channel, $memberData);
        }

        return $memberData === null
            ? ['auth' => true, 'channel' => $channel]
            : ['auth' => true, 'channel' => $channel, 'channel_data' => $memberData];
    }

    /** Whether the channel is one that needs vouching for. */
    protected function isGuarded(string $channel): bool
    {
        foreach (['private-', 'presence-', 'private.', 'presence.'] as $prefix) {
            if (str_starts_with($channel, $prefix)) {
                return true;
            }
        }

        // A channel the application registered a callback for is guarded by
        // definition, whatever it is called.
        return $this->broadcast->hasChannelFor($channel);
    }

    /** Who is asking, or null when nobody is signed in. */
    protected function userFor(Request $request): ?object
    {
        $container = \Nitro\Container\Container::getInstance();

        if (! $container->has('auth')) {
            return null;
        }

        $user = $container->resolve('auth')->user();

        return is_object($user) ? $user : null;
    }

    /** The identifier a presence channel lists a member under. */
    protected function identifierFor(object $user): int|string
    {
        if (method_exists($user, 'getAuthIdentifier')) {
            return $user->getAuthIdentifier();
        }

        return $user->id ?? '';
    }
}
