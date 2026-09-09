<?php

namespace App\Support;

use Illuminate\Support\Str;

final class PaymentAttemptReference
{
    public static function generate(): string
    {
        return strtoupper((string) Str::ulid());
    }

    public static function isValid(string $reference): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $reference) === 1;
    }
}
