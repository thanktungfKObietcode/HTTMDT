<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Payments\VnPayGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, VnPayGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('payment-initiation', fn (\Illuminate\Http\Request $request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by('customer:'.($request->user()?->id ?? 'guest')));
        \Illuminate\Support\Facades\RateLimiter::for('payment-return', fn (\Illuminate\Http\Request $request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by(hash('sha256', (string) $request->ip())));
        \Illuminate\Support\Facades\RateLimiter::for('payment-reconciliation', fn (\Illuminate\Http\Request $request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('admin:'.($request->user()?->id ?? 'guest')));
    }
}
