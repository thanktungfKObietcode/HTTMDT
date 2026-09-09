<?php

namespace App\Support;

use RuntimeException;

final class Money
{
    public static function toMinorUnits(string $amount): int
    {
        $normalized = trim($amount);

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $normalized)) {
            throw new RuntimeException('Số tiền không hợp lệ.');
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';

        if (strlen($whole) > 13) {
            throw new RuntimeException('Số tiền vượt quá giới hạn lưu trữ.');
        }

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function fromMinorUnits(int $amount): string
    {
        if ($amount < 0) {
            throw new RuntimeException('Số tiền không hợp lệ.');
        }

        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function equals(string $left, string $right): bool
    {
        return self::toMinorUnits($left) === self::toMinorUnits($right);
    }
}
