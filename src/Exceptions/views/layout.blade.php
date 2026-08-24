{{--
    The shared error page. Every nitro-errors:: view extends this and supplies
    only the two things that differ — the headline and the sentence under it.

    An application overrides any of them by creating its own
    resources/views/errors/{code}.blade.php; the handler checks the app's views
    before the framework's, so there is nothing to publish or register.

    Available: $code, $message, $exception.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $code }} — @yield('title', $message)</title>
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
        <div class="code">{{ $code }}</div>
        <h1 class="t">@yield('title', $message)</h1>
        <p class="d">@yield('blurb')</p>
        <a href="/" class="b">@yield('action', 'Go Home')</a>
    </div>
</body>
</html>
