<?php

declare(strict_types=1);

namespace App\Service;

use App\App;

final class GeoIpService
{
    private const API_TOKEN = '4abcf15252b1ea';
    private const API_URL   = 'https://api.ipinfo.io/lite/%s?token=%s';

    /**
     * Resolve IP address details with local caching and fast fallback.
     * @return array{
     *     ip: string,
     *     country: string,
     *     country_code: string,
     *     continent: string,
     *     as_name: string,
     *     as_domain: string,
     *     flag_emoji: string
     * }
     */
    public static function lookup(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return [
                'ip'           => $ip ?: '127.0.0.1',
                'country'      => 'Local Network',
                'country_code' => 'LOCAL',
                'continent'    => 'Localhost',
                'as_name'      => 'Private Network',
                'as_domain'    => 'localhost',
                'flag_emoji'   => '🏠',
            ];
        }

        // 1. Check in-memory / cache
        $cacheKey = 'geoip:' . md5($ip);
        try {
            $cached = App::instance()->cache()->get($cacheKey);
            if (is_array($cached) && !empty($cached['country'])) {
                return $cached;
            }
        } catch (\Throwable) {}

        // 2. Query ipinfo.io lite API
        $data = self::fetchFromApi($ip);

        // 3. Cache for 7 days (604800 seconds)
        try {
            App::instance()->cache()->set($cacheKey, $data, 604800);
        } catch (\Throwable) {}

        return $data;
    }

    private static function fetchFromApi(string $ip): array
    {
        $url = sprintf(self::API_URL, urlencode($ip), self::API_TOKEN);

        $context = stream_context_create([
            'http' => [
                'timeout'         => 2.0,
                'ignore_errors'   => true,
                'user_agent'      => 'GitPHP-GeoService/2.0',
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ]
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw !== false && ($json = json_decode($raw, true)) && is_array($json) && !empty($json['country'])) {
            $cc = strtoupper((string) ($json['country_code'] ?? ''));
            return [
                'ip'           => (string) ($json['ip'] ?? $ip),
                'country'      => (string) ($json['country'] ?? 'Unknown Country'),
                'country_code' => $cc,
                'continent'    => (string) ($json['continent'] ?? ''),
                'as_name'      => (string) ($json['as_name'] ?? 'ISP'),
                'as_domain'    => (string) ($json['as_domain'] ?? ''),
                'flag_emoji'   => self::countryCodeToEmoji($cc),
            ];
        }

        return [
            'ip'           => $ip,
            'country'      => 'Unknown',
            'country_code' => 'UN',
            'continent'    => 'Global',
            'as_name'      => 'Internet Provider',
            'as_domain'    => '',
            'flag_emoji'   => '🌐',
        ];
    }

    public static function countryCodeToEmoji(string $code): string
    {
        $code = strtoupper(trim($code));
        if (strlen($code) !== 2 || !preg_match('/^[A-Z]{2}$/', $code)) {
            return '🌐';
        }

        // Convert ASCII characters (A-Z) to Regional Indicator Symbols
        $firstChar  = mb_chr(ord($code[0]) + 127397, 'UTF-8');
        $secondChar = mb_chr(ord($code[1]) + 127397, 'UTF-8');

        return $firstChar . $secondChar;
    }
}
