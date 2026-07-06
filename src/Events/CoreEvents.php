<?php

namespace Nitro\Events;

/**
 * Framework Events Reference
 *
 * Documents all events fired by NitroPHP core.
 * Use these constants for type-safety when listening to events.
 */
class CoreEvents
{
    // Application Lifecycle
    const APP_BOOTSTRAPPING = 'app.bootstrapping';
    const APP_BOOTSTRAPPED = 'app.bootstrapped';
    const APP_TERMINATING = 'app.terminating';

    // HTTP Lifecycle
    const REQUEST_RECEIVED = 'request.received';
    const REQUEST_HANDLED = 'request.handled';
    const RESPONSE_SENDING = 'response.sending';
    const RESPONSE_SENT = 'response.sent';

    // Routing
    const ROUTE_MATCHED = 'route.matched';
    const ROUTE_DISPATCHING = 'route.dispatching';
    const ROUTE_DISPATCHED = 'route.dispatched';

    // Database
    const QUERY_EXECUTING = 'query.executing';
    const QUERY_EXECUTED = 'query.executed';
    const TRANSACTION_BEGINNING = 'transaction.beginning';
    const TRANSACTION_COMMITTED = 'transaction.committed';
    const TRANSACTION_ROLLED_BACK = 'transaction.rolled_back';

    // Views
    const VIEW_RENDERING = 'view.rendering';
    const VIEW_RENDERED = 'view.rendered';

    // Cache
    const CACHE_HIT = 'cache.hit';
    const CACHE_MISSED = 'cache.missed';
    const CACHE_WRITTEN = 'cache.written';
    const CACHE_FORGOTTEN = 'cache.forgotten';

    // Exceptions
    const EXCEPTION_OCCURRED = 'exception.occurred';
    const EXCEPTION_HANDLED = 'exception.handled';

    // Service Providers
    const PROVIDER_REGISTERING = 'provider.registering';
    const PROVIDER_REGISTERED = 'provider.registered';
    const PROVIDER_BOOTING = 'provider.booting';
    const PROVIDER_BOOTED = 'provider.booted';
}