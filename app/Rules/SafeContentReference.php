<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SafeContentReference implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Trường :attribute không hợp lệ.');

            return;
        }

        $reference = trim($value);
        $scheme = parse_url($reference, PHP_URL_SCHEME);

        $externalUrlIsInvalid = $scheme !== null
            && (! is_string($scheme)
                || ! in_array(strtolower($scheme), ['http', 'https'], true)
                || filter_var($reference, FILTER_VALIDATE_URL) === false);

        if (preg_match('/[\x00-\x1F\x7F]/', $reference)
            || str_contains($reference, '\\')
            || str_starts_with($reference, '//')
            || $externalUrlIsInvalid) {
            $fail('Trường :attribute phải là đường dẫn nội bộ hoặc URL HTTP(S) an toàn.');
        }
    }
}
