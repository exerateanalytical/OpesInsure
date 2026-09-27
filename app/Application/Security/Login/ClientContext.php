<?php

declare(strict_types=1);

namespace App\Application\Security\Login;

use Illuminate\Http\Request;

/**
 * Mobile audit B1/B2: what the server may record about the calling client. App/device details come from the request
 * body (device_model, os_version, app_version — sent at sign-in) or the X-Device-Model / X-OS-Version / X-App-Version
 * headers. The approximate location is SERVER-derived only (edge geo headers configured in
 * security_centre.login.geo_headers: country, city) — the app is never asked for GPS here. The IP is only ever kept
 * masked (last two IPv4 octets / last six IPv6 groups hidden) next to its hash.
 */
final class ClientContext
{
    public static function appVersion(?Request $r): ?string
    {
        return self::read($r, 'app_version', 'X-App-Version', 40);
    }

    public static function model(?Request $r): ?string
    {
        return self::read($r, 'device_model', 'X-Device-Model', 120);
    }

    public static function osVersion(?Request $r): ?string
    {
        return self::read($r, 'os_version', 'X-OS-Version', 40);
    }

    /** @return array{0: ?string, 1: ?string} [country ISO-2, city] from the configured edge headers, else nulls */
    public static function approxLocation(?Request $r): array
    {
        $h = (array) config('security_centre.login.geo_headers', []);
        $country = $r && ! empty($h['country']) ? $r->header($h['country']) : null;
        $city = $r && ! empty($h['city']) ? $r->header($h['city']) : null;

        return [
            is_string($country) && preg_match('/^[A-Za-z]{2}$/', $country) ? strtoupper($country) : null,
            is_string($city) && trim($city) !== '' ? mb_substr(trim(rawurldecode($city)), 0, 120) : null,
        ];
    }

    public static function formatLocation(?string $country, ?string $city): ?string
    {
        $parts = array_values(array_filter([$city, $country], fn ($v) => $v !== null && $v !== ''));

        return $parts === [] ? null : implode(', ', $parts);
    }

    public static function maskIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $o = explode('.', $ip);

            return "{$o[0]}.{$o[1]}.x.x";
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $g = explode(':', $ip);

            return ($g[0] ?: '0').':'.($g[1] ?? '0').':x:x:x:x:x:x';
        }

        return null;
    }

    private static function read(?Request $r, string $field, string $header, int $max): ?string
    {
        if (! $r) {
            return null;
        }
        $v = $r->input($field);
        $v = is_string($v) && $v !== '' ? $v : $r->header($header);

        return is_string($v) && trim($v) !== '' ? mb_substr(strip_tags(trim($v)), 0, $max) : null;
    }
}
