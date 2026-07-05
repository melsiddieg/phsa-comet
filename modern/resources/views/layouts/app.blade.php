<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'COMET')</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        :root { --comet-brown: #875503; --comet-gold: #C28119; --comet-cream: #FFFAF2; --comet-ink: #2b2b2b; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Arial, sans-serif; background: var(--comet-cream); color: var(--comet-ink); }
        header { background: var(--comet-brown); color: #F8EBD5; padding: 0.6rem 1.2rem; display: flex; align-items: center; gap: 1.5rem; }
        header a { color: #F8EBD5; text-decoration: none; font-weight: 600; }
        header a:hover { color: #fff; }
        header .brand { font-size: 1.25rem; font-style: italic; letter-spacing: 0.03em; }
        header form { margin-left: auto; }
        header button { background: none; border: 1px solid #F8EBD5; color: #F8EBD5; padding: 0.3rem 0.8rem; border-radius: 4px; cursor: pointer; }
        main { max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem; }
        .card { background: #fff; border: 1px solid #e4d9c6; border-radius: 8px; padding: 1.25rem; box-shadow: 0 1px 3px rgb(0 0 0 / 0.06); }
        .error { color: #b00020; }
    </style>
    @livewireStyles
</head>
<body>
<header>
    <a class="brand" href="{{ route('home') }}">COMET</a>
    @auth
        <nav style="display:flex; gap:1rem;">
            <a href="{{ route('home') }}">Home</a>
        </nav>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Sign out {{ auth()->user()->name }}</button>
        </form>
    @endauth
</header>
<main>
    @yield('content')
</main>
@livewireScripts
</body>
</html>
