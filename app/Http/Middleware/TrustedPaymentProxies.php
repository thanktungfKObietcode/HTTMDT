<?php

namespace App\Http\Middleware;

use App\Support\PaymentSecurityConfiguration;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

final class TrustedPaymentProxies extends TrustProxies
{
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        // Resolve config at request time, not before console bootstrap loads it.
        // No platform/Host-derived wildcard fallback and no forwarded Host trust.
        $request::setTrustedProxies(app(PaymentSecurityConfiguration::class)->trustedProxies(),
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
    }
}
