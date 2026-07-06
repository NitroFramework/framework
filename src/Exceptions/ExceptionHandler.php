<?php

namespace Nitro\Exceptions;

use Throwable;
use Nitro\Foundation\Application;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Container\Contracts\ContainerInterface;

/**
 * ExceptionHandler — The single brain for all exception handling in NitroPHP.
 * 
 * Responsibilities:
 *  1. Custom handlers per exception type (with inheritance matching)
 *  2. Reporting & logging
 *  3. Rendering (dev HTML, prod HTML, JSON, HTMX-aware)
 *  4. Output buffer cleaning (prevents errors hiding inside partial HTML)
 * 
 * Two entry points:
 *  - render($e)        → returns string (for Kernel — wraps in Response)
 *  - handleAndExit($e) → cleans buffers, echoes, exits (for fatal/shutdown)
 */
class ExceptionHandler
{
    private ConfigRepository $config;
    private ContainerInterface $container;

    /** @var array<string, callable> Custom handlers keyed by exception class */
    private array $customHandlers = [];

    /** @var array<string, callable> Report handlers keyed by exception class */
    private array $reportHandlers = [];

    /** @var array<string> Exception classes that should not be reported */
    private array $dontReport = [];

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
     * $handler->register(ValidationException::class, function ($e, $container) {
     *     return Response::json(['errors' => $e->errors()], 422);
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
     */
    public function respondUsing(string $exceptionClass, callable $handler): self
    {
        $this->responseHandlers[$exceptionClass] = $handler;
        return $this;
    }

    /**
     * Convert an exception to a Response using a registered response handler
     * (exact class first, then inheritance), or null if none matches — in which
     * case the caller falls back to the string renderer. Returned untyped so this
     * layer stays free of any Http dependency.
     */
    public function renderResponse(Throwable $e, mixed $request): mixed
    {
        $handler = $this->responseHandlers[get_class($e)] ?? null;

        if ($handler === null) {
            foreach ($this->responseHandlers as $class => $candidate) {
                if ($e instanceof $class) {
                    $handler = $candidate;
                    break;
                }
            }
        }

        return $handler !== null ? $handler($e, $request) : null;
    }

    /**
     * Register a custom reporter for a specific exception type.
     * 
     * $handler->reportUsing(PaymentException::class, function ($e, $container) {
     *     $container->get(SlackNotifier::class)->send($e->getMessage());
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
    public function dontReport(array $classes): self
    {
        $this->dontReport = array_merge($this->dontReport, $classes);
        return $this;
    }

    // ─── Entry Points ─────────────────────────────────────

    /**
     * Render an exception to a string.
     * Used by Kernel::handleException() to wrap in a Response object.
     * Does NOT clean output buffers (Kernel manages its own output).
     */
    public function render(Throwable $e): string
    {
        // Clean any partial output (half-rendered views, etc.)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->report($e);

        // 1. Try custom handler (exact match first, then inheritance)
        $custom = $this->tryCustomHandler($e);
        if ($custom !== null) {
            return $custom;
        }

        // 2. HTMX request — return full error page with retarget headers
        if ($this->isHtmxRequest()) {
            return $this->renderForHtmx($e);
        }

        // 3. AJAX request — return JSON
        if ($this->isAjaxRequest()) {
            return $this->renderJson($e);
        }

        // 4. Normal request — full HTML error page
        return $this->isDebug()
            ? $this->renderDevelopment($e)
            : $this->renderProduction($e);
    }

    /**
     * Get the appropriate HTTP status code for an exception.
     */
    public function getStatusCode(Throwable $e): int
    {
        if ($e instanceof HttpException) {
            return $e->getStatusCode();
        }

        return 500;
    }

    /**
     * Handle a fatal/uncaught exception.
     * Cleans ALL output buffers, echoes error, exits.
     * Used by HandleExceptions bootstrapper for shutdown/fatal errors.
     */
    public function handleAndExit(Throwable $e): never
    {
        $this->cleanOutputBuffers();

        if (!headers_sent()) {
            http_response_code($this->getStatusCode($e));
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo $this->render($e);
        exit(1);
    }

    // ─── Reporting ────────────────────────────────────────

    private function report(Throwable $e): void
    {
        // Check if this exception type should be silenced
        foreach ($this->dontReport as $class) {
            if ($e instanceof $class) {
                return;
            }
        }

        // Try custom reporter (exact match, then inheritance)
        $reported = false;
        $exceptionClass = get_class($e);

        if (isset($this->reportHandlers[$exceptionClass])) {
            $this->reportHandlers[$exceptionClass]($e, $this->container);
            $reported = true;
        } else {
            foreach ($this->reportHandlers as $handlerClass => $reporter) {
                if ($e instanceof $handlerClass) {
                    $reporter($e, $this->container);
                    $reported = true;
                    break;
                }
            }
        }

        // Always do default logging
        $this->logException($e);
    }

    private function logException(Throwable $e): void
    {
        error_log(sprintf(
            "[%s] %s in %s:%d",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    // ─── Custom Handler Resolution ────────────────────────

    private function tryCustomHandler(Throwable $e): ?string
    {
        $exceptionClass = get_class($e);

        // Exact match
        if (isset($this->customHandlers[$exceptionClass])) {
            $result = $this->customHandlers[$exceptionClass]($e, $this->container);
            return is_string($result) ? $result : (string) $result;
        }

        // Inheritance match
        foreach ($this->customHandlers as $handlerClass => $handler) {
            if ($e instanceof $handlerClass) {
                $result = $handler($e, $this->container);
                return is_string($result) ? $result : (string) $result;
            }
        }

        return null;
    }

    // ─── Buffer Cleaning ──────────────────────────────────

    /**
     * Discard ALL buffered output (partial layout HTML, etc.)
     * This is why errors no longer hide inside navbars.
     */
    private function cleanOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
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
            && $this->container->make('request')->isHtmx();
    }

    private function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
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

    private function renderForHtmx(Throwable $e): string
    {
        if (!headers_sent()) {
            header('HX-Retarget: body');
            header('HX-Reswap: innerHTML');
        }

        return $this->isDebug()
            ? $this->renderDevelopment($e)
            : $this->renderProduction($e);
    }

    // ─── JSON Rendering ──────────────────────────────────

    private function renderJson(Throwable $e): string
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }

        $data = [
            'error' => true,
            'message' => $e->getMessage(),
            'type' => get_class($e),
        ];

        if ($this->isDebug()) {
            $data['file'] = $e->getFile();
            $data['line'] = $e->getLine();
            $data['trace'] = $this->getSimpleTrace($e);
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    // ─── Production Rendering ─────────────────────────────

    private function renderProduction(Throwable $e): string
    {
        $code = $this->getStatusCode($e);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$code} — Server Error</title>
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
        <h1 class="t">Something went wrong</h1>
        <p class="d">We're experiencing technical difficulties. Please try again later.</p>
        <a href="javascript:history.back()" class="b">Go Back</a>
    </div>
</body>
</html>
HTML;
    }

    // ─── Development Rendering ────────────────────────────

    private function renderDevelopment(Throwable $e): string
    {
        $type = htmlspecialchars(get_class($e));
        $message = htmlspecialchars($e->getMessage());
        $file = htmlspecialchars($e->getFile());
        $line = $e->getLine();

        $trace = $this->getFormattedTrace($e);
        $traceCount = count($trace);
        $sourceHtml = $this->buildSourceHtml($this->getSourceContext($e->getFile(), $e->getLine()));
        $traceHtml = $this->buildTraceHtml($trace);
        $envHtml = $this->buildEnvironmentHtml();
        $chainHtml = $this->buildExceptionChainHtml($e);
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
            $fn = $item['class']
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
        <div class="trace-fn">{$fn}</div>
        <div class="trace-file" title="{$fullPath}">{$file} : <span class="tln">{$item['line']}</span></div>
        {$args}{$srcHtml}
    </div>
</div>
TRACE;
        }
        return $html;
    }

    private function buildExceptionChainHtml(Throwable $e): string
    {
        $html = '';
        $prev = $e->getPrevious();
        while ($prev) {
            $t = htmlspecialchars(get_class($prev));
            $m = htmlspecialchars($prev->getMessage());
            $f = htmlspecialchars($prev->getFile());
            $l = $prev->getLine();
            $html .= "<div class=\"prev\"><div class=\"prev-label\">Caused by</div><div class=\"prev-type\">{$t}</div><div class=\"prev-msg\">{$m}</div><div class=\"prev-loc\">{$f}:{$l}</div></div>";
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

    private function getFormattedTrace(Throwable $e): array
    {
        $trace = [];
        foreach ($e->getTrace() as $i => $frame) {
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

    private function getSimpleTrace(Throwable $e): array
    {
        $trace = [];
        foreach ($e->getTrace() as $frame) {
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

    private function getRequestHeaders(): array
    {
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

        $filtered = [];
        foreach ($keys as $key) {
            if (isset($_SERVER[$key])) {
                $filtered[$key] = $_SERVER[$key];
            }
        }
        return $filtered;
    }
}
