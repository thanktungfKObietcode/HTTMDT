<?php

namespace App\Support;

final class PaymentMethod
{
    public const COD = 'cod';

    public const SIMULATED_ONLINE = 'simulated-online';

    public const VNPAY = 'vnpay';

    public const MOMO = 'momo';

    /** @return array<int, string> */
    public static function checkoutEnabled(bool $vnpayReady = false, bool $momoReady = false): array
    {
        return array_merge([self::COD], $vnpayReady ? [self::VNPAY] : [], $momoReady ? [self::MOMO] : []);
    }

    /** @return array<int, string> */
    public static function callbackEnabled(): array
    {
        return [self::SIMULATED_ONLINE];
    }

    /** @return array<int, string> */
    public static function known(): array
    {
        return [self::COD, self::SIMULATED_ONLINE, self::VNPAY, self::MOMO];
    }

    public static function normalize(?string $method): string
    {
        return strtolower(trim((string) $method));
    }

    public static function isCod(?string $method): bool
    {
        return self::normalize($method) === self::COD;
    }

    public static function isOnline(?string $method): bool
    {
        return in_array(self::normalize($method), [self::SIMULATED_ONLINE, self::VNPAY, self::MOMO], true);
    }
}
