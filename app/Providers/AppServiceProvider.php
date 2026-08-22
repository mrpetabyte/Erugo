<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\SettingsService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

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
        // Use a view composer for email templates so settings are loaded fresh each time.
        // This is important for queue workers which are long-running processes -
        // View::share() would capture stale values from when the worker started.
        View::composer('emails.*', function ($view) {
            try {
                $settingsService = app(SettingsService::class);
                $settings = $settingsService->getGlobalViewData();
                $view->with('settings', $settings);
            } catch (\Exception $e) {
                // If settings can't be loaded, provide empty defaults
                $view->with('settings', []);
            }
        });

        View::prependLocation(storage_path('templates'));

        // Throttle login attempts per IP and per email so both single-source
        // brute force and distributed password sprays are bounded per account.
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(5)->by('login:' . strtolower((string) $request->input('email'))),
            ];
        });

        // Endpoints that send emails (password reset links, verification codes)
        // get a per-address hourly cap in addition to a per-IP cap. That bounds
        // inbox flooding to a few emails per day for one address, even under a
        // distributed attack. Both keys hash the raw request input, so the 429
        // response is identical whether or not the account exists.
        RateLimiter::for('forgot-password', function (Request $request) {
            return [
                Limit::perMinute(2)->by($request->ip()),
                Limit::perHour(3)->by('forgot-password:' . strtolower((string) $request->input('email'))),
            ];
        });

        RateLimiter::for('resend-verification', function (Request $request) {
            return [
                Limit::perMinute(2)->by($request->ip()),
                Limit::perHour(3)->by('resend-verification:' . strtolower((string) $request->input('email'))),
            ];
        });

        // Registration sends a verification email too, but unique:users already
        // bounds it to one email per address lifetime, so a per-IP cap is enough.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        // Generic per-IP limiter for remaining public auth endpoints
        // (e.g. reset-password) so scripted probing stays bounded.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // The 6-digit email verification code is the weakest brute-force
        // surface, so it gets a tighter per-email limit in addition to the
        // per-IP limit. This must stay below the endpoint's own checks.
        RateLimiter::for('verify-email', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(5)->by('verify-email:' . strtolower((string) $request->input('email'))),
            ];
        });
    }
}
