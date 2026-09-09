<?php

namespace App\Support;

use RuntimeException;

final class VnPayAmount
{
    public const CURRENCY_VND = 'VND';

    private const MAX_GATEWAY_DIGITS = 12;

    public static function toGatewayAmount(string $amount, string $currency = self::CURRENCY_VND): string
    {
        self::assertCurrency($currency);

        $minorUnits = Money::toMinorUnits($amount);

        if ($minorUnits <= 0) {
            throw new RuntimeException('VNPay requires a positive payment amount.');
        }

        if ($minorUnits % 100 !== 0) {
            throw new RuntimeException('VNPay VND amounts cannot contain fractional dong.');
        }

        $gatewayAmount = (string) $minorUnits;

        if (strlen($gatewayAmount) > self::MAX_GATEWAY_DIGITS) {
            throw new RuntimeException('The VNPay amount exceeds the supported range.');
        }

        return $gatewayAmount;
    }

    public static function toDecimal(string $gatewayAmount, string $currency = self::CURRENCY_VND): string
    {
        self::assertCurrency($currency);

        if (preg_match('/^\d{1,'.self::MAX_GATEWAY_DIGITS.'}$/D', $gatewayAmount) !== 1) {
            throw new RuntimeException('The VNPay amount has an invalid numeric format.');
        }

        $normalized = ltrim($gatewayAmount, '0') ?: '0';
        $minorUnits = (int) $normalized;

        if ($minorUnits <= 0) {
            throw new RuntimeException('VNPay requires a positive payment amount.');
        }

        if ($minorUnits % 100 !== 0) {
            throw new RuntimeException('The VNPay amount does not represent a whole VND amount.');
        }

        return Money::fromMinorUnits($minorUnits);
    }

    private static function assertCurrency(string $currency): void
    {
        if (strtoupper(trim($currency)) !== self::CURRENCY_VND) {
            throw new RuntimeException('VNPay is configured for VND only.');
        }
    }
}
