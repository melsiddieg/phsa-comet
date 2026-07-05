<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Azure\AzureExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, AzureExtendSocialite::class.'@handle');

        // COMET roles: authenticated via Entra, authorized in-app.
        Gate::define('map', fn (User $user) => $user->enabled && $user->is_mapper);
        Gate::define('import', fn (User $user) => $user->enabled && $user->is_importer);
        Gate::define('review', fn (User $user) => $user->enabled && $user->is_reviewer);
        Gate::define('admin', fn (User $user) => $user->enabled && $user->is_portal_admin);
    }
}
