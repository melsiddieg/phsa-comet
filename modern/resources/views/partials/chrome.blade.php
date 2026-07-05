<header>
    <a class="brand" href="{{ route('home') }}">COMET</a>
    @auth
        <nav style="display:flex; gap:1rem;">
            <a href="{{ route('home') }}">Home</a>
            <a href="{{ route('sheets.index') }}">Cerner Areas</a>
            <a href="{{ route('domains.index') }}">OMOP Domains</a>
            @can('import')<a href="{{ route('import') }}">Import</a>@endcan
        </nav>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Sign out {{ auth()->user()->name }}</button>
        </form>
    @endauth
</header>
