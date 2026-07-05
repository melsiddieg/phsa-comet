<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'COMET' }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @include('partials.styles')
    @livewireStyles
</head>
<body>
@include('partials.chrome')
<main>
    {{ $slot }}
</main>
@livewireScripts
</body>
</html>
