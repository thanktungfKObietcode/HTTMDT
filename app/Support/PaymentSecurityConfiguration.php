<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final class PaymentSecurityConfiguration
{
    public function __construct(private readonly Repository $config) {}

    /** Static descriptions only: never return configuration values/secrets. */
    public function failures(): array
    {
        if ($this->config->get('app.env') !== 'production') {
            return [];
        }
        $requirements = [
            'production_debug' => [$this->config->get('app.debug') === false, 'Production requires APP_DEBUG=false.'],
            'production_https' => [self::httpsUrl($this->config->get('app.url')), 'Production APP_URL must use HTTPS without credentials, query or fragment.'],
            'production_secure_cookie' => [$this->config->get('session.secure') === true, 'Production session cookies must be Secure.'],
            'production_httponly_cookie' => [$this->config->get('session.http_only') === true, 'Production session cookies must be HttpOnly.'],
            'production_samesite' => [in_array($this->config->get('session.same_site'), ['lax', 'strict'], true), 'Use Lax or Strict SameSite cookies; Lax supports the browser Return.'],
            'production_session_store' => [! in_array($this->config->get('session.driver'), [null, '', 'array'], true), 'Production requires persistent sessions.'],
            'production_cache_store' => [! in_array($this->config->get('cache.default'), [null, '', 'array', 'null'], true), 'Production rate limits require persistent cache storage.'],
            'production_proxy_trust' => [$this->validProxies(), 'Trusted proxies must be explicit IPs/CIDRs, never wildcard or REMOTE_ADDR.'],
        ];
        $failures = [];
        foreach ($requirements as $key => [$valid, $message]) {
            if (! $valid) {
                $failures[$key] = $message;
            }
        }
        return $failures;
    }

    public function assertSafe(): void
    {
        if ($this->failures() !== []) {
            throw new RuntimeException('Production payment environment is not safe; run the deployment preflight.');
        }
    }

    public function queryConfigured(): bool
    {
        return $this->config->get('vnpay.query_url') === 'https://sandbox.vnpayment.vn/merchant_webapi/api/transaction'
            && filter_var($this->config->get('vnpay.query_server_ip'), FILTER_VALIDATE_IP) !== false
            && is_numeric($this->config->get('vnpay.query_timeout_seconds'))
            && (int) $this->config->get('vnpay.query_timeout_seconds') >= 1
            && (int) $this->config->get('vnpay.query_timeout_seconds') <= 30;
    }

    public function callbackUrlsMatchApplication(): bool
    {
        $base = rtrim((string) $this->config->get('app.url'), '/');
        return $this->config->get('vnpay.return_url') === $base.'/thanh-toan/vnpay/return'
            && $this->config->get('vnpay.ipn_url') === $base.'/thanh-toan/vnpay/ipn';
    }

    public static function httpsUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null
            && parse_url($url, PHP_URL_QUERY) === null && parse_url($url, PHP_URL_FRAGMENT) === null;
    }

    private function validProxies(): bool
    {
        $proxies = $this->config->get('security.trusted_proxies', []);
        if (! is_array($proxies)) {
            return false;
        }
        foreach ($proxies as $proxy) {
            $parts = explode('/', (string) $proxy);
            if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
                return false;
            }
            if (isset($parts[1]) && (! ctype_digit($parts[1]) || (int) $parts[1] < 1
                || (int) $parts[1] > (str_contains($parts[0], ':') ? 128 : 32))) {
                return false;
            }
        }
        return true;
    }

    public function trustedProxies(): array
    {
        return $this->validProxies() ? $this->config->get('security.trusted_proxies', []) : [];
    }
}
