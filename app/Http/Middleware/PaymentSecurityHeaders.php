<?php

namespace App\Http\Middleware;

use App\Support\PaymentError;
use App\Support\PaymentSecurityConfiguration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PaymentSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $production = config('app.env') === 'production';
        if ($production && app(PaymentSecurityConfiguration::class)->failures() !== []) {
            // Before routing/session/database work; debug misconfiguration cannot expose an exception page.
            $response = response('Service temporarily unavailable.', 503);
        } elseif ($production && (! $request->isSecure()
            || strtolower($request->getHost()) !== strtolower((string) parse_url(config('app.url'), PHP_URL_HOST)))) {
            // Never process a payment POST over HTTP or redirect to an untrusted Host.
            $response = response('HTTPS on the configured application host is required.', 400);
        } elseif ($request->is('thanh-toan/vnpay/ipn', 'thanh-toan/vnpay/return')
            && (strlen((string) $request->server('QUERY_STRING')) > 16384 || count($request->query()) > 64)) {
            $response = response('Invalid gateway request.', 400);
        } else {
            $response = $next($request);
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', PaymentError::isPaymentRequest($request) ? 'no-referrer' : 'strict-origin-when-cross-origin');
        if (PaymentError::isPaymentRequest($request) || $response->getStatusCode() >= 500) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if ($production && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        return $response;
    }
}
