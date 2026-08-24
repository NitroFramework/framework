<?php

namespace Nitro\Exceptions;

use Throwable;
use WeakMap;
use Nitro\Foundation\Application;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Support\Logger;

/**
 * ExceptionHandler — The single brain for all exception handling in NitroPHP.
 *
 * The request path through here is a fixed pipeline, and each stage is a seam an
 * application can extend:
 *
 *   map()      rewrite the exception into another one
 *   report()   decide whether it is worth recording, then record it
 *   prepare()  give a domain exception its correct HTTP status
 *   render()   turn it into a body — dev page, error view, JSON, console
 *
 * Registration is fluent and lives on this object; a provider's
 * boot(ExceptionHandler $handler) is where an app wires its own rules.
 *
 * Three entry points:
 *  - report($exception)         → record it (Kernel calls this before rendering)
 *  - render($exception)         → returns string (for Kernel — wraps in Response)
 *  - handleAndExit($exception)  → cleans buffers, echoes, exits (for fatal/shutdown)
 */
class ExceptionHandler
{
    /** Fallback log level for an exception with no explicit mapping. */
    public const DEFAULT_LEVEL = 'error';

    /**
     * Output-buffer depth when the handler was installed, set by
     * HandleExceptions. Everything below this belongs to whatever is hosting the
     * process — a Thrust worker, a test harness — and must survive even a fatal.
     */
    public static int $initialObLevel = 0;

    /**
     * Output-buffer depth at the start of the current request, set by the Kernel.
     *
     * This is the only line the renderer is allowed to unwind to. Anything ABOVE
     * it was opened while handling this request (a half-written layout, an open
     * @section) and should be discarded so the error page isn't nested inside
     * it; anything BELOW belongs to the host and is not ours to close.
     *
     * Null means "not inside a request" — in which case we touch nothing at all,
     * because there is no way to tell our buffers from someone else's.
     */
    public static ?int $requestObLevel = null;

    private ConfigRepository $config;
    private ContainerInterface $container;

    /** @var array<string, callable> Custom handlers keyed by exception class */
    private array $customHandlers = [];

    /** @var array<string, callable> Report handlers keyed by exception class */
    private array $reportHandlers = [];

    /** @var array<string> Exception classes that should not be reported */
    private array $dontReport = [];

    /**
     * Control-flow exceptions the framework never reports. These are not errors:
     * a 404, a rejected CSRF token or a failed validation is the application
     * working as designed, and logging them floods the log with bot traffic.
     * An app can override with stopIgnoring().
     *
     * @var array<int, class-string>
     */
    private array $internalDontReport = [
        \Nitro\Exceptions\HttpException::class,
        \Nitro\Http\Exceptions\HttpResponseException::class,
        \Nitro\Validation\ValidationException::class,
    ];

    /** @var array<int, class-string> Classes removed from the internal ignore list. */
    private array $stopIgnoring = [];

    /**
     * Exception→Response converters keyed by exception class. Unlike custom
     * handlers (which return a string body), these return a full Response object
     * — used for exceptions that must redirect or set headers (e.g. a validation
     * failure → redirect-back / 422 JSON). Kept as opaque callables so this layer
     * never imports Http; the Kernel invokes them via renderResponse().
     *
     * @var array<string, callable>
     */
    private array $responseHandlers = [];

    /** @var array<string, callable|string> Exception rewriters keyed by source class. */
    private array $exceptionMap = [];

    /** @var array<class-string, string> Per-exception log levels. */
    private array $levels = [];

    /** @var array<int, callable> Callbacks contributing to every log context. */
    private array $contextCallbacks = [];

    /** @var array<int, callable> Predicates that can veto reporting. */
    private array $dontReportCallbacks = [];

    /** @var array<int, callable> Callbacks returning a ReportRate for an exception. */
    private array $throttleCallbacks = [];

    /** Report each exception instance at most once. */
    private bool $withoutDuplicates = false;

    /** @var WeakMap<Throwable, bool>|null Instances already reported. */
    private ?WeakMap $reportedExceptions = null;

    /** Overrides the default "does this client want JSON?" decision. */
    private mixed $shouldRenderJsonCallback = null;

    /** Post-processes every Response the handler produces. */
    private mixed $finalizeResponseCallback = null;

    private int $contextLines = 10;

    public function __construct(ConfigRepository $config, ContainerInterface $container)
    {
        $this->config = $config;
        $this->container = $container;
    }

    // ─── Registration API ─────────────────────────────────

    /**
     * Register a custom handler for a specific exception type.
     * 
     * $handler->register(ValidationException::class, function ($exception, $container) {
     *     return Response::json(['errors' => $exception->errors()], 422);
     * });
     */
    public function register(string $exceptionClass, callable $handler): self
    {
        $this->customHandlers[$exceptionClass] = $handler;
        return $this;
    }

    /**
     * Register a converter that turns an exception into a full Response object
     * (redirect, JSON, headers) rather than an HTML string. The callable receives
     * ($exception, $request) and must return a Response. Used e.g. by
     * ExceptionServiceProvider to map ValidationException → redirect-back / 422 JSON.
     *
     * Named renderableResponse() rather than respondUsing() on purpose: in Laravel
     * respondUsing() is the single global callback that post-processes EVERY
     * rendered response, and that name is reserved here for the same thing.
     */
    public function renderableResponse(string $exceptionClass, callable $handler): self
    {
        $this->responseHandlers[$exceptionClass] = $handler;
        return $this;
    }

    /**
     * Post-process every Response this layer produces — the last word on
     * headers, status or body before it goes out. Receives ($response,
     * $exception, $request) and must return the Response to send.
     */
    public function respondUsing(callable $callback): self
    {
        $this->finalizeResponseCallback = $callback;
        return $this;
    }

    /**
     * Convert an exception to a Response using a registered response handler
     * (exact class first, then inheritance), or null if none matches — in which
     * case the caller falls back to the string renderer. Returned untyped so this
     * layer stays free of any Http dependency.
     */
    public function renderResponse(Throwable $exception, mixed $request): mixed
    {
        $exception = $this->mapException($exception);

        $handler = $this->responseHandlers[get_class($exception)] ?? null;

        if ($handler === null) {
            foreach ($this->responseHandlers as $class => $candidate) {
                if ($exception instanceof $class) {
                    $handler = $candidate;
                    break;
                }
            }
        }

        if ($handler === null) {
            return null;
        }

        return $this->finalize($handler($exception, $request), $exception, $request);
    }

    /**
     * Hand a finished Response to the respondUsing() callback, if one is set.
     * Untyped for the same reason as renderResponse(): no Http import here.
     */
    public function finalize(mixed $response, Throwable $exception, mixed $request = null): mixed
    {
        if ($response === null || $this->finalizeResponseCallback === null) {
            return $response;
        }

        return ($this->finalizeResponseCallback)($response, $exception, $request);
    }

    /**
     * Register a custom reporter for a specific exception type.
     * 
     * $handler->reportUsing(PaymentException::class, function ($exception, $container) {
     *     $container->createOrResolve(SlackNotifier::class)->send($exception->getMessage());
     * });
     */
    public function reportUsing(string $exceptionClass, callable $reporter): self
    {
        $this->reportHandlers[$exceptionClass] = $reporter;
        return $this;
    }

    /**
     * Mark exception classes that should not be reported/logged.
     *
     * $handler->dontReport([ValidationException::class, NotFoundException::class]);
     */
    public function dontReport(array|string $classes): self
    {
        $this->dontReport = array_merge($this->dontReport, (array) $classes);
        return $this;
    }

    /**
     * Silence reporting by predicate rather than by class — for the cases a
     * class name can't express ("don't report a 503 from the payment gateway
     * during a known maintenance window"). Returning true suppresses.
     */
    public function dontReportWhen(callable $predicate): self
    {
        $this->dontReportCallbacks[] = $predicate;
        return $this;
    }

    /**
     * Put a class the framework ignores by default back into reporting — e.g.
     * an app that DOES want its 404s logged.
     */
    public function stopIgnoring(array|string $classes): self
    {
        foreach ((array) $classes as $class) {
            $this->stopIgnoring[] = $class;
            $this->dontReport = array_values(array_filter(
                $this->dontReport,
                static fn(string $ignored): bool => $ignored !== $class
            ));
        }

        return $this;
    }

    /**
     * Report each exception INSTANCE at most once. Guards the case where an
     * exception is reported, rethrown, and caught again further up — without
     * this the same failure lands in the log two or three times.
     */
    public function dontReportDuplicates(): self
    {
        $this->withoutDuplicates = true;
        $this->reportedExceptions ??= new WeakMap();
        return $this;
    }

    /**
     * Rewrite one exception type into another before anything else sees it.
     * Accepts a target class (constructed with the original as $previous) or a
     * callable receiving the original.
     *
     * $handler->map(PDOException::class, ServiceUnavailableException::class);
     */
    public function map(string $from, callable|string $to): self
    {
        $this->exceptionMap[$from] = $to;
        return $this;
    }

    /**
     * Set the log level an exception type is recorded at. Without this every
     * exception logs at 'error', which makes a genuine outage indistinguishable
     * from a noisy edge case.
     *
     * $handler->level(ThrottleException::class, 'warning');
     */
    public function level(string $exceptionClass, string $level): self
    {
        $this->levels[$exceptionClass] = $level;
        return $this;
    }

    /**
     * Add data to the context of EVERY logged exception — a request id, the
     * tenant, the queue job. Receives ($exception, $contextSoFar) and returns
     * the entries to merge in.
     */
    public function buildContextUsing(callable $callback): self
    {
        $this->contextCallbacks[] = $callback;
        return $this;
    }

    /**
     * Override how the handler decides a client wants JSON. Receives
     * ($request, $exception) and returns bool.
     */
    public function shouldRenderJsonWhen(callable $callback): self
    {
        $this->shouldRenderJsonCallback = $callback;
        return $this;
    }

    /**
     * Cap how often an exception may be reported. The callback receives the
     * exception and returns a {@see ReportRate} (or null for no limit).
     *
     * Without this, one broken dependency throwing in a loop writes a log line
     * every time — the first few are diagnostic, the rest are just disk.
     */
    public function throttle(callable $using): self
    {
        $this->throttleCallbacks[] = $using;
        return $this;
    }

    /**
     * Add fields that must never be flashed back to the session on a validation
     * redirect. The framework already excludes the password fields; this is for
     * app-specific secrets (api_token, ssn, card_number).
     */
    public function dontFlash(array|string $attributes): self
    {
        \Nitro\Http\RedirectResponse::dontFlash((array) $attributes);
        return $this;
    }

    // ─── Entry Points ─────────────────────────────────────

    /**
     * Render an exception to a string.
     * Used by Kernel::handleException() to wrap in a Response object.
     * Does NOT clean output buffers (Kernel manages its own output).
     */
    public function render(Throwable $exception): string
    {
        // Discard partially-rendered output (a half-written layout) so the error
        // doesn't end up buried inside a navbar. Unwind only the buffers opened
        // BELOW us: in worker mode (see Nitro\Thrust) the outermost buffer
        // belongs to the runtime, and tearing it down eats the server's output.
        $this->unwindOutputBuffers();

        $exception = $this->mapException($exception);

        // An exception may render itself — the most idiomatic place to put the
        // behaviour, since it lives with the thing it describes.
        if (($own = $this->renderUsingException($exception)) !== null) {
            return $own;
        }

        // Registered handlers (exact class match first, then inheritance).
        $custom = $this->tryCustomHandler($exception);
        if ($custom !== null) {
            return $custom;
        }

        // Give a domain exception its HTTP identity before choosing a renderer.
        $exception = $this->prepareException($exception);

        if ($this->isHtmxRequest()) {
            return $this->renderForHtmx($exception);
        }

        if ($this->wantsJson($exception)) {
            return $this->renderJson($exception);
        }

        return $this->isDebug()
            ? $this->renderDevelopment($exception)
            : $this->renderProduction($exception);
    }

    /**
     * Render an exception for the console: the message always, and the full
     * file/line/trace when debug is on. A command that dies in a scheduled run
     * is the case this exists for — "Error: SQLSTATE[HY000]" with no location
     * tells you nothing.
     */
    public function renderForConsole(Throwable $exception): string
    {
        $exception = $this->mapException($exception);

        $out = sprintf("%s: %s\n", $this->shortClass($exception), $exception->getMessage());
        $out .= sprintf("  at %s:%d\n", $exception->getFile(), $exception->getLine());

        if (! $this->isDebug()) {
            return $out;
        }

        foreach ($this->getSimpleTrace($exception) as $i => $frame) {
            $out .= sprintf("  #%d %s\n", $i, $frame);
        }

        for ($previous = $exception->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            $out .= sprintf(
                "\nCaused by %s: %s\n  at %s:%d\n",
                $this->shortClass($previous),
                $previous->getMessage(),
                $previous->getFile(),
                $previous->getLine()
            );
        }

        return $out;
    }

    /** Whether we are running under the CLI (or phpdbg) rather than serving a request. */
    private function runningInConsole(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    /** Class name without its namespace, for console and log output. */
    private function shortClass(Throwable $exception): string
    {
        $parts = explode('\\', get_class($exception));

        return end($parts);
    }

    /**
     * Discard buffers opened while handling this request, and only those.
     *
     * Outside a request there is no way to tell our buffers from the host's, so
     * nothing is touched — better to render into an enclosing buffer than to
     * tear down output that belongs to a worker or a test runner.
     */
    private function unwindOutputBuffers(): void
    {
        if (self::$requestObLevel === null) {
            return;
        }

        $floor = max(self::$requestObLevel, self::$initialObLevel);

        while (ob_get_level() > $floor) {
            ob_end_clean();
        }
    }

    /**
     * The HTTP status an exception should produce. Runs the mapping pipeline
     * first, so a domain exception (a model that wasn't found, a rejected
     * token) reports its real status rather than a blanket 500.
     */
    public function getStatusCode(Throwable $exception): int
    {
        $exception = $this->prepareException($this->mapException($exception));

        if ($exception instanceof HttpException) {
            return $exception->getStatusCode();
        }

        return 500;
    }

    /**
     * Handle a fatal/uncaught exception.
     * Cleans ALL output buffers, echoes error, exits.
     * Used by HandleExceptions bootstrapper for shutdown/fatal errors.
     *
     * This is the one place that has to CHOOSE a medium, because an uncaught
     * exception can surface either way. render() is the HTTP renderer and
     * renderForConsole() the terminal one; the SAPI decides which applies.
     * Without the branch a command that died printed a full HTML error page —
     * <head>, CSS and all — into the terminal.
     */
    public function handleAndExit(Throwable $exception): never
    {
        $this->cleanOutputBuffers();

        $this->report($exception);

        if ($this->runningInConsole()) {
            echo $this->renderForConsole($exception);
            exit(1);
        }

        if (! headers_sent()) {
            http_response_code($this->getStatusCode($exception));
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo $this->render($exception);
        exit(1);
    }

    // ─── Mapping ──────────────────────────────────────────

    /**
     * Rewrite the exception before anything else sees it: an inner exception a
     * wrapper is carrying, then any map() registration. Runs at the head of
     * both report() and render() so the two never disagree about what failed.
     */
    public function mapException(Throwable $exception): Throwable
    {
        if (method_exists($exception, 'getInnerException') && ($inner = $exception->getInnerException()) instanceof Throwable) {
            return $inner;
        }

        foreach ($this->exceptionMap as $class => $mapper) {
            if ($exception instanceof $class) {
                return is_string($mapper)
                    ? new $mapper($exception->getMessage(), 0, $exception)
                    : $mapper($exception);
            }
        }

        return $exception;
    }

    /**
     * Give a domain exception its HTTP identity.
     *
     * Without this stage every exception that isn't already an HttpException
     * renders as 500 — a record that doesn't exist, a rejected CSRF token and a
     * genuine crash all look the same to the client. Each arm converts a
     * framework exception into the status it actually means.
     */
    public function prepareException(Throwable $exception): Throwable
    {
        return match (true) {
            $exception instanceof HttpException => $exception,

            // A findOrFail()/firstOrFail() miss is a missing page, not a crash.
            $exception instanceof \Nitro\Database\Model\ModelNotFoundException
                => new HttpException(404, $exception->getMessage(), $exception),

            // A named query that isn't registered is a 404 in the same sense.
            $exception instanceof \Nitro\Database\Query\Exceptions\QueryNotFoundException
                => new HttpException(404, $exception->getMessage(), $exception),

            // Validation keeps its own 422 status (the provider usually converts
            // it to a redirect long before this, but a JSON client lands here).
            $exception instanceof \Nitro\Validation\ValidationException
                => new HttpException($exception->status ?: 422, $exception->getMessage(), $exception),

            default => $exception,
        };
    }

    // ─── Reporting ────────────────────────────────────────

    /**
     * Record an exception: run it past every suppression rule, then hand it to
     * the exception's own report(), a registered reporter, or the log.
     *
     * Public because the Kernel reports once at the top of its catch, before
     * choosing how to render — so an exception that converts to a redirect
     * (a validation failure) still goes through the same reporting rules as one
     * that renders a page.
     */
    public function report(Throwable $exception): void
    {
        $exception = $this->mapException($exception);

        if ($this->shouldntReport($exception)) {
            return;
        }

        if ($this->withoutDuplicates) {
            $this->reportedExceptions ??= new WeakMap();
            $this->reportedExceptions[$exception] = true;
        }

        // An exception may report itself. Returning false means "not handled,
        // carry on"; anything else (including null) stops here.
        if (method_exists($exception, 'report') && $exception->report($this->container) !== false) {
            return;
        }

        // A registered reporter may likewise claim the exception by not
        // returning false — that is what makes "ship it to Sentry and don't
        // also write it to the log" expressible.
        if ($this->runReportHandler($exception) === true) {
            return;
        }

        $this->logException($exception);
    }

    /** Whether any suppression rule silences this exception. */
    public function shouldntReport(Throwable $exception): bool
    {
        if ($this->withoutDuplicates
            && $this->reportedExceptions !== null
            && ($this->reportedExceptions[$exception] ?? false)) {
            return true;
        }

        foreach ($this->ignoredClasses() as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        foreach ($this->dontReportCallbacks as $callback) {
            if ($callback($exception) === true) {
                return true;
            }
        }

        return $this->isThrottled($exception);
    }

    /**
     * Whether this occurrence exceeds the configured report rate.
     *
     * Fails OPEN: if the rate limiter or cache is unavailable the exception is
     * reported. Losing a log line because the cache is down is the wrong
     * trade — the exception is why you are looking.
     */
    private function isThrottled(Throwable $exception): bool
    {
        if ($this->throttleCallbacks === []) {
            return false;
        }

        $rate = null;
        foreach ($this->throttleCallbacks as $callback) {
            if (($rate = $callback($exception)) instanceof ReportRate) {
                break;
            }
            $rate = null;
        }

        if ($rate === null || $rate->isUnlimited()) {
            return false;
        }

        if ($rate->mode === 'sample') {
            return $rate->chance <= 0.0
                || ($rate->chance < 1.0 && random_int(1, 1000) > (int) round($rate->chance * 1000));
        }

        try {
            $limiter = new \Nitro\Cache\RateLimiter($this->container->createOrResolve('cache'));

            // attempt() runs the callback and returns false once the budget for
            // this window is spent — so "not allowed through" means throttled.
            return $limiter->attempt(
                'nitro:exceptions:' . hash('xxh128', $rate->key ?: get_class($exception)),
                $rate->maxAttempts,
                static fn(): bool => true,
                $rate->decaySeconds
            ) === false;
        } catch (Throwable) {
            return false;
        }
    }

    /** The inverse of shouldntReport(), for callers that read better this way. */
    public function shouldReport(Throwable $exception): bool
    {
        return ! $this->shouldntReport($exception);
    }

    /** Every class currently silenced — framework defaults plus app additions, minus stopIgnoring(). */
    private function ignoredClasses(): array
    {
        $ignored = array_merge($this->internalDontReport, $this->dontReport);

        if ($this->stopIgnoring === []) {
            return $ignored;
        }

        return array_values(array_filter(
            $ignored,
            fn(string $class): bool => ! in_array($class, $this->stopIgnoring, true)
        ));
    }

    /**
     * Run the first matching registered reporter. Returns true when the reporter
     * claimed the exception (did not return false), meaning the default log is
     * skipped.
     */
    private function runReportHandler(Throwable $exception): bool
    {
        $exceptionClass = get_class($exception);

        if (isset($this->reportHandlers[$exceptionClass])) {
            return $this->reportHandlers[$exceptionClass]($exception, $this->container) !== false;
        }

        foreach ($this->reportHandlers as $handlerClass => $reporter) {
            if ($exception instanceof $handlerClass) {
                return $reporter($exception, $this->container) !== false;
            }
        }

        return false;
    }

    /**
     * Write the exception to the application log through Nitro's own logger —
     * at the level its class maps to, with a context payload, so a 429 and a
     * database outage are distinguishable in the log.
     */
    private function logException(Throwable $exception): void
    {
        Logger::log($this->levelFor($exception), get_class($exception) . ': ' . $exception->getMessage(), $this->contextFor($exception));
    }

    /** The log level registered for this exception's class, else 'error'. */
    public function levelFor(Throwable $exception): string
    {
        foreach ($this->levels as $class => $level) {
            if ($exception instanceof $class) {
                return $level;
            }
        }

        return self::DEFAULT_LEVEL;
    }

    /**
     * The context recorded alongside a logged exception: where it came from,
     * whatever the exception itself chooses to attach via a context() method,
     * and anything buildContextUsing() callbacks add.
     */
    public function contextFor(Throwable $exception): array
    {
        $context = [
            'exception' => get_class($exception),
            'file'      => $exception->getFile(),
            'line'      => $exception->getLine(),
        ];

        if (($previous = $exception->getPrevious()) !== null) {
            $context['previous'] = get_class($previous) . ': ' . $previous->getMessage();
        }

        if (method_exists($exception, 'context')) {
            $context = array_merge($context, (array) $exception->context());
        }

        foreach ($this->contextCallbacks as $callback) {
            $context = array_merge($context, (array) $callback($exception, $context));
        }

        if ($this->isDebug()) {
            $context['trace'] = $this->getSimpleTrace($exception);
        }

        return $context;
    }

    // ─── Custom Handler Resolution ────────────────────────

    /**
     * Let the exception render itself. A `render()` method on the exception is
     * the most direct place for a one-off presentation, and it is where an
     * application coming from Laravel will look first.
     */
    private function renderUsingException(Throwable $exception): ?string
    {
        if (! method_exists($exception, 'render')) {
            return null;
        }

        $result = $exception->render($this->request());

        return $result === null || $result === false ? null : (string) $result;
    }

    private function tryCustomHandler(Throwable $exception): ?string
    {
        $exceptionClass = get_class($exception);

        // Exact match
        if (isset($this->customHandlers[$exceptionClass])) {
            $result = $this->customHandlers[$exceptionClass]($exception, $this->container);
            return is_string($result) ? $result : (string) $result;
        }

        // Inheritance match
        foreach ($this->customHandlers as $handlerClass => $handler) {
            if ($exception instanceof $handlerClass) {
                $result = $handler($exception, $this->container);
                return is_string($result) ? $result : (string) $result;
            }
        }

        return null;
    }

    // ─── Buffer Cleaning ──────────────────────────────────

    /**
     * The fatal path: discard everything this process buffered, down to the
     * depth the host had open when we were installed. Used by handleAndExit(),
     * where we are about to echo and exit — so unlike the request-scoped
     * unwind above, there is nothing left to preserve except the host's own
     * buffer (a worker's, which outlives this request).
     */
    private function cleanOutputBuffers(): void
    {
        while (ob_get_level() > self::$initialObLevel) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header_remove();
        }
    }

    // ─── Request Detection ────────────────────────────────

    private function isHtmxRequest(): bool
    {
        return $this->container->has('request')
            && $this->container->createOrResolve('request')->isHtmx();
    }

    /**
     * Whether this client wants JSON back.
     *
     * Sourced from the Request seam — never $_SERVER — so it is worker-safe and
     * honours the Accept header, not just X-Requested-With. An API client sends
     * `Accept: application/json` and no XHR header at all; keying off the
     * superglobal handed those clients a full HTML error page.
     */
    private function wantsJson(Throwable $exception): bool
    {
        $request = $this->request();

        if ($this->shouldRenderJsonCallback !== null) {
            return (bool) ($this->shouldRenderJsonCallback)($request, $exception);
        }

        return $request !== null && $request->expectsJson();
    }

    /** The current request from the container, or null outside a request. */
    private function request(): ?\Nitro\Http\Request
    {
        if (! $this->container->has('request')) {
            return null;
        }

        $request = $this->container->createOrResolve('request');

        return $request instanceof \Nitro\Http\Request ? $request : null;
    }

    private function isDebug(): bool
    {
        $value = $this->config->get('app.debug');

        // env() returns strings — handle "true"/"false"
        if (is_string($value)) {
            return strtolower($value) === 'true' || $value === '1';
        }

        return (bool) $value;
    }

    // ─── HTMX Rendering ──────────────────────────────────

    private function renderForHtmx(Throwable $exception): string
    {
        if (!headers_sent()) {
            header('HX-Retarget: body');
            header('HX-Reswap: innerHTML');
        }

        return $this->isDebug()
            ? $this->renderDevelopment($exception)
            : $this->renderProduction($exception);
    }

    // ─── JSON Rendering ──────────────────────────────────

    private function renderJson(Throwable $exception): string
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }

        $debug = $this->isDebug();

        // In production, never echo the raw exception message to a JSON client:
        // it can carry SQL, file paths, or other internals. Only HttpExceptions
        // (whose message is a deliberate, safe status text) pass through. Mirrors
        // Laravel's Handler::convertExceptionToArray.
        $data = [
            'error'   => true,
            'message' => $debug || $exception instanceof HttpException
                ? $exception->getMessage()
                : 'Server Error',
        ];

        if ($debug) {
            $data['type']  = get_class($exception);
            $data['file']  = $exception->getFile();
            $data['line']  = $exception->getLine();
            $data['trace'] = $this->getSimpleTrace($exception);
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    // ─── Production Rendering ─────────────────────────────

    private function renderProduction(Throwable $exception): string
    {
        $code = $this->getStatusCode($exception);

        // An error VIEW wins over the built-in page, so an application controls
        // what its users see. Resolution walks from most to least specific and
        // checks the app's own views before the framework's, which means
        // creating resources/views/errors/404.blade.php is the whole override
        // story — nothing to publish, nothing to register.
        if (($view = $this->errorView($code)) !== null) {
            return $view;
        }

        return $this->renderFallbackPage($code, $exception);
    }

    /**
     * Render errors.{code} → errors.{n}xx → nitro-errors::{code} → nitro-errors::{n}xx,
     * or null when none exists (or the view itself blows up — an error page that
     * throws must not replace the error).
     */
    private function errorView(int $code): ?string
    {
        try {
            if (! $this->container->has(\Nitro\View\Contracts\ViewEngine::class)) {
                return null;
            }

            $engine = $this->container->createOrResolve(\Nitro\View\Contracts\ViewEngine::class);

            $candidates = [
                "errors.{$code}",
                'errors.' . substr((string) $code, 0, 1) . 'xx',
                "nitro-errors::{$code}",
                'nitro-errors::' . substr((string) $code, 0, 1) . 'xx',
            ];

            foreach ($candidates as $view) {
                if ($engine->viewExists($view)) {
                    return $engine->render($view, [
                        'code'      => $code,
                        'message'   => $this->statusText($code),
                        'exception' => $this->isDebug() ? $this->safeMessage() : '',
                    ]);
                }
            }
        } catch (Throwable) {
            // Fall through to the built-in page.
        }

        return null;
    }

    /** Reason phrase for a status code, used as the error views' headline. */
    public function statusText(int $code): string
    {
        return [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Page Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ][$code] ?? 'Error';
    }

    /** Placeholder for the detail an error view may show while debugging. */
    private function safeMessage(): string
    {
        return '';
    }

    /** The built-in page, used when the application supplies no error view. */
    private function renderFallbackPage(int $code, Throwable $exception): string
    {
        $title = htmlspecialchars($this->statusText($code));
        $blurb = htmlspecialchars($this->statusBlurb($code));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$code} — {$title}</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;background:#0f1117;color:#e2e4eb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
        .c{text-align:center;max-width:480px}
        .code{font-size:96px;font-weight:900;color:#ff5555;line-height:1}
        .t{font-size:24px;font-weight:700;margin:16px 0 8px}
        .d{font-size:15px;color:#8b8fa3;line-height:1.6;margin-bottom:32px}
        .b{display:inline-block;padding:12px 28px;background:#6c8aff;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;transition:background .2s}
        .b:hover{background:#5a75e6}
    </style>
</head>
<body>
    <div class="c">
        <div class="code">{$code}</div>
        <h1 class="t">{$title}</h1>
        <p class="d">{$blurb}</p>
        <a href="javascript:history.back()" class="b">Go Back</a>
    </div>
</body>
</html>
HTML;
    }

    /**
     * The sentence under the headline. A 404 telling the user we're
     * "experiencing technical difficulties" is both wrong and unhelpful — the
     * page is simply not there, and that is worth saying accurately.
     */
    private function statusBlurb(int $code): string
    {
        return [
            400 => "That request couldn't be understood. Check the address and try again.",
            401 => 'You need to sign in to view this page.',
            403 => "You don't have permission to view this page.",
            404 => "We couldn't find that page. It may have moved or been removed.",
            405 => "That action isn't allowed on this address.",
            419 => 'This page expired. Refresh and try again.',
            422 => "That submission couldn't be processed. Check the form and try again.",
            429 => "You've made too many requests. Wait a moment and try again.",
            503 => "We're down for maintenance. Please try again shortly.",
        ][$code] ?? "We're experiencing technical difficulties. Please try again later.";
    }

    // ─── Development Rendering ────────────────────────────

    private function renderDevelopment(Throwable $exception): string
    {
        $type = htmlspecialchars(get_class($exception));
        $message = htmlspecialchars($exception->getMessage());
        $file = htmlspecialchars($exception->getFile());
        $line = $exception->getLine();

        $trace = $this->getFormattedTrace($exception);
        $traceCount = count($trace);
        $sourceHtml = $this->buildSourceHtml($this->getSourceContext($exception->getFile(), $exception->getLine()));
        $traceHtml = $this->buildTraceHtml($trace);
        $envHtml = $this->buildEnvironmentHtml();
        $chainHtml = $this->buildExceptionChainHtml($exception);
        $phpVersion = PHP_VERSION;
        $nitroVersion = Application::VERSION;
        $errorTime = date('Y-m-d H:i:s');
        $memoryUsage = number_format(memory_get_usage() / 1024 / 1024, 2);
        $peakMemory = number_format(memory_get_peak_usage() / 1024 / 1024, 2);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚠ {$type}: {$message}</title>
    <style>
        :root{
            --bg:#0f1117;--surface:#1a1d27;--surface-hover:#22263a;--border:#2a2e3e;
            --text:#e2e4eb;--dim:#8b8fa3;--red:#ff5555;--blue:#6c8aff;--green:#50fa7b;
            --orange:#ffb86c;--purple:#bd93f9;--cyan:#8be9fd;
            --err-bg:rgba(255,85,85,.12);--err-border:rgba(255,85,85,.4);
            --mono:'SF Mono','Cascadia Code','JetBrains Mono','Fira Code',Consolas,monospace;
            --sans:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;
        }
        html.light{
            --bg:#f5f5f5;--surface:#ffffff;--surface-hover:#f0f0f0;--border:#e0e0e0;
            --text:#1a1a1a;--dim:#666;--red:#dc3545;--blue:#4263eb;--green:#087f5b;
            --orange:#e67700;--purple:#7048e8;--cyan:#0c8599;
            --err-bg:rgba(220,53,69,.08);--err-border:rgba(220,53,69,.4);
        }
        html.light .error-bar{background:var(--red)}
        html.light .err .line-code{color:var(--red)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:var(--sans);background:var(--bg);color:var(--text);line-height:1.6;min-height:100vh;-webkit-font-smoothing:antialiased}

        .error-bar{background:var(--red);color:#fff;padding:6px 24px;font-family:var(--mono);font-size:12px;font-weight:600;letter-spacing:.5px;display:flex;align-items:center;gap:8px}
        .error-bar::before{content:'✕';display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:rgba(0,0,0,.2);font-size:10px}

        .header{padding:32px 32px 28px;border-bottom:1px solid var(--border)}
        .exc-type{font-family:var(--mono);font-size:13px;color:var(--red);font-weight:600;margin-bottom:8px}
        .exc-msg{font-size:22px;font-weight:700;line-height:1.4;margin-bottom:12px;word-break:break-word}
        .exc-loc{font-family:var(--mono);font-size:13px;color:var(--dim)}
        .exc-loc strong{color:var(--blue);font-weight:600}
        .exc-loc .ln{color:var(--orange);font-weight:700}

        .prev{margin-top:16px;padding:14px 18px;background:var(--surface);border:1px solid var(--border);border-radius:8px;border-left:3px solid var(--orange)}
        .prev-label{font-size:11px;font-weight:700;color:var(--orange);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px}
        .prev-type{font-family:var(--mono);font-size:12px;color:var(--red);font-weight:600}
        .prev-msg{font-size:14px;margin:4px 0}
        .prev-loc{font-family:var(--mono);font-size:12px;color:var(--dim)}

        .tabs{display:flex;border-bottom:1px solid var(--border);background:var(--surface);padding:0 24px;gap:0;overflow-x:auto}
        .tab{padding:12px 20px;font-size:13px;font-weight:600;color:var(--dim);cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;transition:all .15s;user-select:none}
        .tab:hover{color:var(--text)}
        .tab.active{color:var(--blue);border-bottom-color:var(--blue)}
        .tab .badge{background:rgba(108,138,255,.08);color:var(--blue);padding:1px 7px;border-radius:10px;font-size:11px;margin-left:6px}

        .panel{display:none}
        .panel.active{display:block}

        .code-block{font-family:var(--mono);font-size:13px;line-height:1.7;overflow-x:auto}
        .code-line{display:flex;border-left:3px solid transparent}
        .code-line.err{background:var(--err-bg);border-left-color:var(--red)}
        .line-no{color:var(--dim);padding:0 16px;text-align:right;min-width:64px;user-select:none;opacity:.5;flex-shrink:0}
        .err .line-no{color:var(--red);opacity:1;font-weight:700}
        .line-code{padding:0 20px 0 8px;white-space:pre;flex:1}
        .err .line-code{color:#fff}

        .trace-item{padding:14px 24px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .1s;display:flex;align-items:flex-start;gap:14px}
        .trace-item:hover{background:var(--surface-hover)}
        .trace-idx{font-family:var(--mono);font-size:11px;color:var(--dim);background:var(--surface);border:1px solid var(--border);padding:2px 8px;border-radius:4px;flex-shrink:0;min-width:32px;text-align:center}
        .trace-det{flex:1;min-width:0}
        .trace-fn{font-family:var(--mono);font-size:13px;font-weight:600;word-break:break-all}
        .trace-fn .cls{color:var(--cyan)}
        .trace-fn .fn{color:var(--green)}
        .trace-fn .sep{color:var(--dim)}
        .trace-file{font-family:var(--mono);font-size:12px;color:var(--dim);margin-top:2px}
        .trace-file .tln{color:var(--orange);font-weight:600}
        .trace-args{font-family:var(--mono);font-size:11px;color:var(--purple);margin-top:4px;opacity:.8}
        .trace-src{display:none;margin-top:10px;border:1px solid var(--border);border-radius:6px;overflow:hidden}
        .trace-src.vis{display:block}

        .env-section{padding:20px 24px}
        .env-title{font-size:13px;font-weight:700;color:var(--blue);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--border)}
        .env-table{width:100%;border-collapse:collapse;margin-bottom:24px}
        .env-table td{padding:8px 0;font-family:var(--mono);font-size:12px;border-bottom:1px solid var(--border);vertical-align:top}
        .env-table td:first-child{color:var(--cyan);font-weight:600;white-space:nowrap;padding-right:24px;width:240px}
        .env-table td:last-child{color:var(--dim);word-break:break-all}

        @media(max-width:768px){
            .header{padding:20px 16px}.exc-msg{font-size:17px}.tabs{padding:0 8px}.tab{padding:10px 14px;font-size:12px}.trace-item{padding:12px 16px}
        }
    </style>
</head>
<body>
        <div class="error-bar">UNHANDLED EXCEPTION <button onclick="toggleTheme()" id="theme-btn" style="margin-left:auto;background:rgba(0,0,0,.2);border:none;color:#fff;padding:4px 12px;border-radius:4px;cursor:pointer;font-family:var(--mono);font-size:11px">☀ Light</button></div>


    <div class="header">
        <div class="exc-type">{$type}</div>
        <div class="exc-msg">{$message}</div>
        <div class="exc-loc"><strong>{$file}</strong> : <span class="ln">{$line}</span></div>
        {$chainHtml}
    </div>

    <div class="tabs">
        <div class="tab active" data-tab="source">Source</div>
        <div class="tab" data-tab="trace">Stack Trace <span class="badge">{$traceCount}</span></div>
        <div class="tab" data-tab="request">Request</div>
        <div class="tab" data-tab="env">Environment</div>
    </div>

    <div class="panel active" id="panel-source">
        <div class="code-block">{$sourceHtml}</div>
    </div>

    <div class="panel" id="panel-trace">{$traceHtml}</div>

    <div class="panel" id="panel-request">{$envHtml}</div>

    <div class="panel" id="panel-env">
        <div class="env-section">
            <div class="env-title">Runtime</div>
            <table class="env-table">
                <tr><td>PHP Version</td><td>{$phpVersion}</td></tr>
<tr><td>NitroPHP</td><td>{$nitroVersion}</td></tr>
<tr><td>Time</td><td>{$errorTime}</td></tr>
<tr><td>Memory</td><td>{$memoryUsage} MB</td></tr>
<tr><td>Peak Memory</td><td>{$peakMemory} MB</td></tr>
            </table>
        </div>
    </div>

    <script>
        document.querySelectorAll('.tab').forEach(t=>t.addEventListener('click',()=>{
            document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
            document.querySelectorAll('.panel').forEach(x=>x.classList.remove('active'));
            t.classList.add('active');
            document.getElementById('panel-'+t.dataset.tab).classList.add('active');
        }));
        document.querySelectorAll('.trace-item').forEach(i=>i.addEventListener('click',()=>{
            const s=i.querySelector('.trace-src');if(s)s.classList.toggle('vis');
        }));
        document.addEventListener('keydown',e=>{const n=parseInt(e.key);const tabs=document.querySelectorAll('.tab');if(n>=1&&n<=tabs.length)tabs[n-1].click();});

        function toggleTheme(){
            const html=document.documentElement;
            const btn=document.getElementById('theme-btn');
            html.classList.toggle('light');
            btn.textContent=html.classList.contains('light')?'🌙 Dark':'☀ Light';
        }
    </script>
</body>
</html>
HTML;
    }

    // ─── HTML Builders ────────────────────────────────────

    private function buildSourceHtml(array $source): string
    {
        $html = '';
        foreach ($source as $line) {
            $cls = $line['isError'] ? ' err' : '';
            $code = htmlspecialchars($line['code']);
            $html .= "<div class=\"code-line{$cls}\"><div class=\"line-no\">{$line['number']}</div><div class=\"line-code\">{$code}</div></div>";
        }
        return $html;
    }

    private function buildTraceHtml(array $trace): string
    {
        $html = '';
        foreach ($trace as $item) {
            $signature = $item['class']
                ? "<span class=\"cls\">{$item['class']}</span><span class=\"sep\">::</span><span class=\"fn\">{$item['function']}</span>()"
                : "<span class=\"fn\">{$item['function']}</span>()";

            $file = htmlspecialchars(basename($item['file']));
            $fullPath = htmlspecialchars($item['file']);
            $args = $item['args'] ? '<div class="trace-args">(' . htmlspecialchars(implode(', ', $item['args'])) . ')</div>' : '';

            $srcHtml = '';
            if (!empty($item['source'])) {
                $srcHtml = '<div class="trace-src"><div class="code-block">' . $this->buildSourceHtml($item['source']) . '</div></div>';
            }

            $html .= <<<TRACE
<div class="trace-item">
    <div class="trace-idx">#{$item['index']}</div>
    <div class="trace-det">
        <div class="trace-fn">{$signature}</div>
        <div class="trace-file" title="{$fullPath}">{$file} : <span class="tln">{$item['line']}</span></div>
        {$args}{$srcHtml}
    </div>
</div>
TRACE;
        }
        return $html;
    }

    private function buildExceptionChainHtml(Throwable $exception): string
    {
        $html = '';
        $prev = $exception->getPrevious();
        while ($prev) {
            $type = htmlspecialchars(get_class($prev));
            $message = htmlspecialchars($prev->getMessage());
            $file = htmlspecialchars($prev->getFile());
            $line = $prev->getLine();
            $html .= "<div class=\"prev\"><div class=\"prev-label\">Caused by</div><div class=\"prev-type\">{$type}</div><div class=\"prev-msg\">{$message}</div><div class=\"prev-loc\">{$file}:{$line}</div></div>";
            $prev = $prev->getPrevious();
        }
        return $html;
    }

    private function buildEnvironmentHtml(): string
    {
        $sections = [
            'GET' => $_GET ?? [],
            'POST' => $_POST ?? [],
            'Headers' => $this->getRequestHeaders(),
            'Cookies' => $_COOKIE ?? [],
            'Session' => $_SESSION ?? [],
            'Server' => $this->getFilteredServer(),
        ];

        $html = '';
        foreach ($sections as $name => $data) {
            if (empty($data)) continue;

            $html .= '<div class="env-section"><div class="env-title">' . htmlspecialchars($name) . '</div><table class="env-table">';
            foreach ($data as $key => $value) {
                $display = is_array($value) ? json_encode($value) : (string) $value;
                if (strlen($display) > 120) $display = substr($display, 0, 120) . '…';
                $html .= '<tr><td>' . htmlspecialchars($key) . '</td><td>' . htmlspecialchars($display) . '</td></tr>';
            }
            $html .= '</table></div>';
        }
        return $html;
    }

    // ─── Data Helpers ─────────────────────────────────────

    private function getFormattedTrace(Throwable $exception): array
    {
        $trace = [];
        foreach ($exception->getTrace() as $i => $frame) {
            $trace[] = [
                'index' => $i,
                'file' => $frame['file'] ?? 'internal',
                'line' => $frame['line'] ?? 0,
                'class' => $frame['class'] ?? null,
                'function' => $frame['function'] ?? 'unknown',
                'args' => $this->formatArgs($frame['args'] ?? []),
                'source' => isset($frame['file'])
                    ? $this->getSourceContext($frame['file'], $frame['line'] ?? 0, 5)
                    : [],
            ];
        }
        return $trace;
    }

    private function getSimpleTrace(Throwable $exception): array
    {
        $trace = [];
        foreach ($exception->getTrace() as $frame) {
            $trace[] = sprintf(
                '%s%s%s() in %s:%d',
                $frame['class'] ?? '',
                isset($frame['class']) ? '::' : '',
                $frame['function'] ?? 'unknown',
                basename($frame['file'] ?? 'unknown'),
                $frame['line'] ?? 0
            );
        }
        return $trace;
    }

    private function getSourceContext(string $file, int $line, ?int $context = null): array
    {
        $context ??= $this->contextLines;
        if (!$file || !file_exists($file)) return [];

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $start = max(0, $line - $context - 1);
        $end = min(count($lines), $line + $context);

        $result = [];
        for ($i = $start; $i < $end; $i++) {
            $result[] = [
                'number' => $i + 1,
                'code' => $lines[$i] ?? '',
                'isError' => ($i + 1) === $line,
            ];
        }
        return $result;
    }

    private function formatArgs(array $args): array
    {
        $formatted = [];
        foreach ($args as $arg) {
            $formatted[] = match (true) {
                is_object($arg) => get_class($arg),
                is_array($arg) => 'Array(' . count($arg) . ')',
                is_string($arg) => '"' . (strlen($arg) > 60 ? substr($arg, 0, 60) . '…' : $arg) . '"',
                is_null($arg) => 'null',
                is_bool($arg) => $arg ? 'true' : 'false',
                default => (string) $arg,
            };
        }
        return $formatted;
    }

    /**
     * Request headers for the debug page. Sourced from the Request seam when
     * one is bound (worker-safe, and the same view of the request the rest of
     * the framework has), falling back to $_SERVER only outside a request.
     */
    private function getRequestHeaders(): array
    {
        if (($request = $this->request()) !== null && method_exists($request, 'headers')) {
            return $request->headers();
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        return $headers;
    }

    private function getFilteredServer(): array
    {
        $keys = [
            'SERVER_NAME',
            'SERVER_PORT',
            'SERVER_SOFTWARE',
            'DOCUMENT_ROOT',
            'REQUEST_METHOD',
            'REQUEST_URI',
            'QUERY_STRING',
            'REMOTE_ADDR',
            'SERVER_PROTOCOL',
            'HTTPS',
        ];

        // Same seam rule as getRequestHeaders(): read through the Request when
        // one is bound so this works identically under a persistent worker.
        $request = $this->request();

        $filtered = [];
        foreach ($keys as $key) {
            $value = $request !== null ? $request->server($key) : ($_SERVER[$key] ?? null);

            if ($value !== null) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }
}
