<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class MediaUrl
{
    public static function resolve(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        if ($reference === '' || str_contains($reference, '\\') || str_contains($reference, '..')) {
            return null;
        }

        $scheme = parse_url($reference, PHP_URL_SCHEME);

        if ($scheme !== null) {
            return is_string($scheme)
                && in_array(strtolower($scheme), ['http', 'https'], true)
                && filter_var($reference, FILTER_VALIDATE_URL) !== false
                ? $reference
                : null;
        }

        if (str_starts_with($reference, '//')) {
            return null;
        }

        if (str_starts_with($reference, '/')) {
            return $reference;
        }

        if (str_starts_with($reference, 'storage/')) {
            return '/'.$reference;
        }

        return Storage::disk('public')->url($reference);
    }
}
