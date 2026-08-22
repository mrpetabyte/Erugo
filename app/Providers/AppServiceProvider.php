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
    }
}
