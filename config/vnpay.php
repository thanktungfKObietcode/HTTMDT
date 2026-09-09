<?php

return [
    'enabled' => filter_var(env('VNPAY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'tmn_code' => env('VNPAY_TMN_CODE'),
    'hash_secret' => env('VNPAY_HASH_SECRET'),
    'payment_url' => env('VNPAY_PAYMENT_URL'),
    'return_url' => env('VNPAY_RETURN_URL'),
    'ipn_url' => env('VNPAY_IPN_URL'),
    'version' => env('VNPAY_VERSION', '2.1.0'),
    'locale' => env('VNPAY_LOCALE', 'vn'),
    'currency' => env('VNPAY_CURRENCY', 'VND'),
    'order_type' => env('VNPAY_ORDER_TYPE', 'other'),
    'timezone' => env('VNPAY_TIMEZONE', 'Asia/Ho_Chi_Minh'),
    'expire_minutes' => 15,
    'query_url' => env('VNPAY_QUERY_URL'),
    'query_server_ip' => env('VNPAY_QUERY_SERVER_IP'),
    'query_timeout_seconds' => 10,
    'expiry_batch_size' => 25,
    'expiry_max_attempts' => 20,
];
