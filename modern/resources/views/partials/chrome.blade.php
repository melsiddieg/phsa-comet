<header>
    <a class="brand" href="{{ route('home') }}">COMET</a>
    @auth
        <nav style="display:flex; gap:1rem;">
            <a href="{{ route('home') }}">Home</a>
            <a href="{{ route('sheets.index') }}">Cerner Areas</a>
            <a href="{{ route('domains.index') }}">OMOP Domains</a>
            @can('review')<a href="{{ route('review') }}">Review</a>@endcan
            @can('import')<a href="{{ route('import') }}">Import</a>@endcan
        </nav>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Sign out {{ auth()->user()->name }}</button>
        </form>
    @endauth
</header>
