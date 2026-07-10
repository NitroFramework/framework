<?php

namespace Nitro\Htmx\Concerns;

use Nitro\Http\Response;

trait HasEvents
{
    /** Pending HX-Trigger events (collected, then applied to the response) */
    private array $pendingEvents = [];

    /** Pending HX-Trigger-After-Swap events */
    private array $pendingAfterSwapEvents = [];

    /** Pending HX-Trigger-After-Settle events */
    private array $pendingAfterSettleEvents = [];

    /**
     * Emit an event via HX-Trigger header.
     *
     * Other components can listen with hx-trigger="eventName from:body".
     *
     *   $this->emit('todo-updated');
     *   $this->emit('item-deleted', ['id' => 5]);
     *   $this->emit('notify', ['message' => 'Saved!', 'type' => 'success']);
     */
    protected function emit(string $event, array $data = []): void
    {
        $this->pendingEvents[$event] = empty($data) ? $event : $data;
    }

    /**
     * Convenience for toast-style messages — wraps emit('flash', …) with
     * a standard {level, message} payload. View-side toast listener
     * subscribes once to the 'flash' event and renders all of them:
     *
     *   $this->flash('Settings saved.');
     *   $this->flash('Could not delete record.', 'error');
     *   $this->flash('Heads up!', 'warning');
     *
     * Levels are strings, not enums — keep them aligned with whatever
     * the toast UI expects (commonly: success, error, warning, info).
     */
    protected function flash(string $message, string $level = 'success'): void
    {
        $this->emit('flash', ['level' => $level, 'message' => $message]);
    }

    /**
     * Emit an event that fires after the swap completes.
     */
    protected function emitAfterSwap(string $event, array $data = []): void
    {
        $this->pendingAfterSwapEvents[$event] = empty($data) ? $event : $data;
    }

    /**
     * Emit an event that fires after the settle phase.
     */
    protected function emitAfterSettle(string $event, array $data = []): void
    {
        $this->pendingAfterSettleEvents[$event] = empty($data) ? $event : $data;
    }

    /**
     * Apply pending event headers to a response.
     * Called by HtmxDispatcher after action execution.
     */
    public function applyEventHeaders(Response $response): Response
    {
        if (!empty($this->pendingEvents)) {
            $response->header('HX-Trigger', $this->encodeEvents($this->pendingEvents));
        }

        if (!empty($this->pendingAfterSwapEvents)) {
            $response->header('HX-Trigger-After-Swap', $this->encodeEvents($this->pendingAfterSwapEvents));
        }

        if (!empty($this->pendingAfterSettleEvents)) {
            $response->header('HX-Trigger-After-Settle', $this->encodeEvents($this->pendingAfterSettleEvents));
        }

        return $response;
    }

    /**
     * Check if there are any pending events.
     */
    public function hasPendingEvents(): bool
    {
        return !empty($this->pendingEvents)
            || !empty($this->pendingAfterSwapEvents)
            || !empty($this->pendingAfterSettleEvents);
    }

    /**
     * Read-only access to pending events for inspection / testing.
     * Returns a flat list of event names across all three trigger phases.
     */
    public function pendingEventNames(): array
    {
        return array_unique(array_merge(
            array_keys($this->pendingEvents),
            array_keys($this->pendingAfterSwapEvents),
            array_keys($this->pendingAfterSettleEvents),
        ));
    }

    private function encodeEvents(array $events): string
    {
        // If all events are simple (no data), HTMX accepts comma-separated names
        // If any have data, encode as JSON object
        $hasData = false;
        foreach ($events as $key => $value) {
            if ($key !== $value) {
                $hasData = true;
                break;
            }
        }

        if (!$hasData) {
            return implode(', ', array_keys($events));
        }

        return json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}