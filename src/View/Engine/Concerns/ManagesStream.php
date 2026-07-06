<?php

namespace Nitro\View\Engine\Concerns;

/**
 * View engine concern: streamed rendering.
 */
trait ManagesStream
{
    // Stream state (streamingMode, currentFill) lives on
    // {@see \Nitro\View\Engine\RenderContext} via $this->context.

    /**
     * Begin streaming mode.
     *
     * Kills all stacked output buffers so flush() reaches the SAPI immediately.
     * Sets headers that disable Nginx/proxy buffering for this response.
     *
     * Do NOT set Transfer-Encoding: chunked manually —
     * PHP-FPM + Nginx handle chunked framing automatically when
     * you flush without a Content-Length header.
     */
    public function startStream(): void
    {
        $this->context->streamingMode = true;

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        header('Content-Type: text/html; charset=utf-8');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');
    }

    public function endStream(): void
    {
        $this->context->streamingMode = false;
    }

    /**
     * Emit a placeholder into the live stream.
     * The browser paints everything above this point instantly.
     * Content arrives later via @fill and gets swapped in.
     */
    public function renderHole(string $name): void
    {
        $safe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        echo '<div id="nitro-hole-' . $safe . '"></div>';
        flush();
    }

    /**
     * Start capturing fill content into an output buffer.
     */
    public function startFill(string $name): void
    {
        $this->context->currentFill = $name;
        ob_start();
    }

    /**
     * End fill capture, stream the content + swap script to browser.
     *
     * Uses <template> instead of hidden <div> to prevent the browser
     * from loading images or executing scripts inside the buffered content
     * prematurely.
     *
     * Includes htmx.process() so hx-* attributes in the injected
     * content get picked up without a full page scan.
     */
    public function endFill(): void
    {
        $content = ob_get_clean();
        $name    = $this->context->currentFill;
        $this->context->currentFill = null;

        $safe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        echo '<template id="nitro-fill-' . $safe . '">'
            . $content
            . '</template>';

        echo '<script>'
            . '(function(){'
            . 'var h=document.getElementById("nitro-hole-' . $safe . '");'
            . 'var p=h.parentElement;'
            . 'var t=document.getElementById("nitro-fill-' . $safe . '");'
            . 'h.outerHTML=t.innerHTML;'
            . 't.remove();'
            . 'if(typeof htmx!=="undefined")htmx.process(p);'
            . '})();'
            . '</script>';

        flush();
    }
}
