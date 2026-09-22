<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'COMET')</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @include('partials.styles')
    @livewireStyles
</head>
<body>
@include('partials.chrome')
<main>
    @if (session('status'))
        <div class="card" style="background:#eefaee; color:#1b5e20; margin-bottom:1rem;">{{ session('status') }}</div>
    @endif
    @yield('content')
</main>
@livewireScripts
</body>
</html>
