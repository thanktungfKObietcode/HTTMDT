<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Opt-in only, with a shared persistent cache. Manual --dry-run remains available.
\Illuminate\Support\Facades\Schedule::command('payments:expire-vnpay --limit=25')
    ->name('vnpay-payment-expiry')->everyFiveMinutes()->environments(['production'])
    ->when(fn () => config('vnpay.enabled') === true && config('vnpay.expiry_scheduler_enabled') === true
        && in_array(config('cache.default'), ['database', 'redis'], true)
        && app(\App\Support\PaymentSecurityConfiguration::class)->failures() === []
        && app(\App\Support\PaymentSecurityConfiguration::class)->queryConfigured())
    ->withoutOverlapping()->onOneServer()
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('Scheduled VNPay expiry failed; inspect redacted batch counts and reconciliation journal.'));
