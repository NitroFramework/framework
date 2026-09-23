<?php

use Nitro\Debug\Backtrace;
use Nitro\Debug\TraceRenderer;
use Nitro\Http\Response;

if (! function_exists('trace_renderer')) {
    /**
     * A renderer over the caller's stack, with the helper frames dropped.
     *
     * What is left is the path through the application; the line the
     * helper was put on is kept separately, as the origin.
     *
     * @param int $skip Helpers between this and the caller, each of
     *        which would otherwise appear as a frame of its own.
     */
    function trace_renderer(?string $label, int $skip = 0): TraceRenderer
    {
        $trace = Backtrace::capture($skip);

        return new TraceRenderer($trace->withoutTop(), $label, $trace->origin());
    }
}

if (! function_exists('trace')) {
    /**
     * The call stack as a response, to return from a controller.
     *
     *     public function index()
     *     {
     *         return trace();            // instead of return view(...)
     *     }
     *
     *     return trace('before the query');
     *
     * Shows every file and method that led here, in the order they were
     * called — entry point first, this line last. A request that asked
     * for JSON gets the frames as data instead.
     *
     * @param ?string $label       A note shown above the frames.
     * @param bool    $newestFirst Number from this call outwards instead.
     */
    function trace(?string $label = null, bool $newestFirst = false): Response
    {
        $renderer = trace_renderer($label, 1);

        if (trace_wants_json()) {
            return Response::json($renderer->toArray($newestFirst));
        }

        return (new Response($renderer->toHtml($newestFirst)))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}

if (! function_exists('trace_text')) {
    /**
     * The same, as plain text.
     *
     * For a console command, a log line, or a test that asserts on the
     * path rather than reading it.
     */
    function trace_text(?string $label = null, bool $newestFirst = false): string
    {
        return trace_renderer($label, 1)->toText($newestFirst);
    }
}

if (! function_exists('dt')) {
    /**
     * Show the stack and stop, the way dd() shows a value and stops.
     *
     * For a place that cannot return a response — inside a view, a
     * model event, a queued job.
     */
    function dt(?string $label = null, bool $newestFirst = false): never
    {
        echo trace_renderer($label, 1)->toHtml($newestFirst);

        exit;
    }
}

if (! function_exists('trace_wants_json')) {
    /**
     * Whether the current request would rather have data than a page.
     *
     * Guarded, because this is a debugging helper and is as likely to
     * be called from a console command, where there is no request.
     */
    function trace_wants_json(): bool
    {
        try {
            $request = app('request');
        } catch (\Throwable) {
            return false;
        }

        return method_exists($request, 'expectsJson') && $request->expectsJson();
    }
}
