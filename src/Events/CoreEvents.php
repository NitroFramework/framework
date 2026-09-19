<?php

namespace Nitro\Events;

/**
 * Events the framework core raises: the application, the request, providers
 * and exceptions.
 *
 * Only what the core actually owns. This class used to declare all twenty-seven
 * framework events, which meant the Events layer named fifteen others —
 * CoreEvents::VIEW_RENDERING was Events describing View. Beyond reading oddly,
 * it meant a package could not ship its own events without editing a file
 * inside the framework.
 *
 * Each layer now declares its own:
 *
 *   {@see \Nitro\Routing\Events\RoutingEvents}    route.*
 *   {@see \Nitro\Database\Events\DatabaseEvents}  query.*, transaction.*
 *   {@see \Nitro\View\Events\ViewEvents}          view.*
 *   {@see \Nitro\Cache\Events\CacheEvents}        cache.*
 *
 * Every name here is a promise that something fires it — enforced by
 * CoreEventsAreEmittedTest, which is how twenty-five of them were found to
 * have never fired at all.
 */
class CoreEvents
{
    // ─── Application lifecycle ────────────────────────────────────────────

    /** Bootstrap has begun. Almost nothing is bound yet. No payload. */
    const APP_BOOTSTRAPPING = 'app.bootstrapping';

    /** Every provider has registered and booted. No payload. */
    const APP_BOOTSTRAPPED = 'app.bootstrapped';

    /** The response has been sent; slow after-the-fact work belongs here. No payload. */
    const APP_TERMINATING = 'app.terminating';

    // ─── HTTP lifecycle ───────────────────────────────────────────────────

    /** A request arrived, before middleware or routing. {@see \Nitro\Http\Events\RequestEvent} */
    const REQUEST_RECEIVED = 'request.received';

    /** The response is final, whatever produced it. {@see \Nitro\Http\Events\RequestEvent} */
    const REQUEST_HANDLED = 'request.handled';

    /** The last moment before bytes go out. {@see \Nitro\Http\Events\RequestEvent} */
    const RESPONSE_SENDING = 'response.sending';

    /** The bytes have left. {@see \Nitro\Http\Events\RequestEvent} */
    const RESPONSE_SENT = 'response.sent';

    // ─── Service providers ────────────────────────────────────────────────

    /** Before a provider's register(). {@see \Nitro\Foundation\Events\ProviderEvent} */
    const PROVIDER_REGISTERING = 'provider.registering';

    /** After it. {@see \Nitro\Foundation\Events\ProviderEvent} */
    const PROVIDER_REGISTERED = 'provider.registered';

    /** Before a provider's boot(). {@see \Nitro\Foundation\Events\ProviderEvent} */
    const PROVIDER_BOOTING = 'provider.booting';

    /** After it. {@see \Nitro\Foundation\Events\ProviderEvent} */
    const PROVIDER_BOOTED = 'provider.booted';

    // ─── Exceptions ───────────────────────────────────────────────────────

    /**
     * Anything that reaches the handler, including what the log ignores —
     * a 404, a validation failure. {@see \Nitro\Exceptions\Events\ExceptionEvent}
     */
    const EXCEPTION_OCCURRED = 'exception.occurred';

    /** Only the ones rendered into a page. {@see \Nitro\Exceptions\Events\ExceptionEvent} */
    const EXCEPTION_HANDLED = 'exception.handled';
}
