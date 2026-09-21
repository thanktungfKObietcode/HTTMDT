<?php

return [
    'enabled' => filter_var(env('MOMO_ENABLED', false), FILTER_VALIDATE_BOOL),
    'partner_code' => env('MOMO_PARTNER_CODE'),
    'access_key' => env('MOMO_ACCESS_KEY'),
    'secret_key' => env('MOMO_SECRET_KEY'),
    'create_url' => env('MOMO_CREATE_URL', 'https://test-payment.momo.vn/v2/gateway/api/create'),
    'query_url' => env('MOMO_QUERY_URL', 'https://test-payment.momo.vn/v2/gateway/api/query'),
    'refund_url' => env('MOMO_REFUND_URL', 'https://test-payment.momo.vn/v2/gateway/api/refund'),
    'refund_query_url' => env('MOMO_REFUND_QUERY_URL', 'https://test-payment.momo.vn/v2/gateway/api/refund/query'),
    'redirect_url' => env('MOMO_REDIRECT_URL'),
    'ipn_url' => env('MOMO_IPN_URL'),
    'request_type' => env('MOMO_REQUEST_TYPE', 'captureWallet'),
    'lang' => env('MOMO_LANG', 'vi'),
    // MoMo documents a minimum 30-second timeout for these APIs.
    'timeout_seconds' => 30,
];
