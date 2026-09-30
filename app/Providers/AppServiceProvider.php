<?php

namespace App\Providers;

use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        if (config('absensi.trust_proxies')) {
            \Illuminate\Http\Middleware\TrustProxies::at('*'); // di balik Cloudflare / reverse proxy
        }

        Gate::define('admin', fn (User $u) => $u->isAdmin());
        Gate::define('bk', fn (User $u) => $u->hasRole('bk', 'admin', 'superadmin'));
        Gate::define('tu', fn (User $u) => $u->hasRole('tu', 'admin', 'superadmin'));
        Gate::define('tu-lihat', fn (User $u) => $u->hasRole('tu', 'kepsek', 'admin', 'superadmin'));
        Gate::define('absen-mandiri', fn (User $u) => \App\Services\StaffService::selfEligible($u));
        Gate::define('bk-modul', fn (User $u) => \App\Services\BkService::canOpen($u));
        Gate::define('lihat-sekolah', fn (User $u) => $u->hasRole('kepsek', 'superadmin', 'admin', 'bk'));

        RateLimiter::for('publik', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));

        View::composer('*', function ($view) {
            $view->with('settings', SchoolSetting::current());
        });
    }
}
