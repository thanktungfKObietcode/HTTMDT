<?php

namespace App\Support;

final class VimeoVideo
{
    private const HOSTS = ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];

    public static function extractId(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        if ($reference === '' || filter_var($reference, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($reference);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || ! in_array($host, self::HOSTS, true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/'))));

        foreach (array_reverse($segments) as $segment) {
            if (preg_match('/^\d{6,15}$/', $segment) === 1) {
                return $segment;
            }
        }

        return null;
    }

    public static function canonicalUrl(string $videoId): string
    {
        return 'https://vimeo.com/'.self::validatedId($videoId);
    }

    public static function embedUrl(string $videoId): string
    {
        return 'https://player.vimeo.com/video/'.self::validatedId($videoId).'?playsinline=1';
    }

    private static function validatedId(string $videoId): string
    {
        if (preg_match('/^\d{6,15}$/', $videoId) !== 1) {
            throw new \InvalidArgumentException('Invalid Vimeo video ID.');
        }

        return $videoId;
    }
}
