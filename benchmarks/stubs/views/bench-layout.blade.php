{{-- Shared benchmark layout. Byte-identical on every framework under test. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
</head>
<body>
    <header>
        <h1>@yield('title')</h1>
    </header>

    <main>
        @yield('content')
    </main>

    <footer>
        <p>rows: @yield('rowcount')</p>
    </footer>
</body>
</html>
