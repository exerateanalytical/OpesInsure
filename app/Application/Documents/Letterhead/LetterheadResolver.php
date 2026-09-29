<?php

declare(strict_types=1);

namespace App\Application\Documents\Letterhead;

use App\Models\Letterhead\LetterheadAsset;
use App\Models\Policy;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Resolves the letterhead of a document (view data for resources/views/pdf/_letterhead*.blade.php) and its
 * provenance snapshot. The issuer is the insurer (CARRIER letterhead), the broker (TENANT) or the platform
 * (the PLATFORM tenant); the co-branding row is the intermediary (or, on a broker-issued document, the
 * insurer it acts for). A missing or unreadable file never yields a broken image: the partial falls back to
 * a text wordmark.
 */
final class LetterheadResolver
{
    public static function disk(): string
    {
        return (string) config('letterheads.disk', config('lifecycle.documents_disk', 'local'));
    }

    public static function current(string $ownerType, ?string $ownerId): ?LetterheadAsset
    {
        if (! $ownerId) {
            return null;
        }

        return LetterheadAsset::where('owner_type', $ownerType)->where($ownerType === 'CARRIER' ? 'carrier_id' : 'tenant_id', $ownerId)
            ->where('status', 'ACTIVE')->orderByDesc('version')->first();
    }

    /** Public logo URL: only for an ACTIVE, public-display version with a logo. */
    public static function publicLogoUrl(?LetterheadAsset $a): ?string
    {
        if (! $a || ! $a->public_display || ! $a->logo_path || $a->status !== 'ACTIVE') {
            return null;
        }

        $url = url('/api/v1/public/letterheads/'.$a->id.'/logo').'?v='.substr((string) $a->logo_sha256, 0, 12);

        // Absolute, unauthenticated https URL outside local/testing (mobile app requirement).
        return app()->environment(['local', 'testing']) ? $url : preg_replace('#^http://#', 'https://', $url);
    }

    /** The carrier's public-display logo URL (same rule as publicLogoUrl), for mobile payloads. */
    public static function carrierLogoUrl(?string $carrierId): ?string
    {
        if (! $carrierId) {
            return null;
        }

        // R4: lists call this once per row; one query per request loads every carrier's current public logo (same rule).
        $logos = \App\Application\Identity\Rbac\RequestMemo::remember('carrier-public-logos', function () {
            $out = [];
            foreach (LetterheadAsset::where('owner_type', 'CARRIER')->whereNotNull('carrier_id')->where('status', 'ACTIVE')->where('public_display', true)
                ->whereNotNull('logo_path')->orderByDesc('version')->get() as $a) {
                $out[(string) $a->carrier_id] ??= $a;
            }

            return $out;
        });

        return self::publicLogoUrl($logos[$carrierId] ?? null);
    }

    public static function dataUri(?LetterheadAsset $a, string $kind): ?string
    {
        $path = $a?->{$kind.'_path'};
        if (! $path) {
            return null;
        }
        try {
            $bytes = Storage::disk(self::disk())->get($path);
        } catch (Throwable) {
            return null;
        }
        if (! is_string($bytes) || $bytes === '' || ($a->{$kind.'_sha256'} && hash('sha256', $bytes) !== $a->{$kind.'_sha256'})) {
            return null;
        }

        return self::embedUri($bytes, (string) $a->{$kind.'_mime'});
    }

    /**
     * Data URI of artwork for the document shell (dompdf). WebP is served as-is on the web and in the app, but
     * is converted to PNG here (GD) so every PDF/preview renderer gets a format it handles; when GD cannot
     * decode WebP the document falls back to the text wordmark instead of a broken image.
     */
    public static function embedUri(string $bytes, string $mime): ?string
    {
        if ($mime === 'image/webp') {
            static $png = [];
            $key = hash('sha256', $bytes);
            if (! array_key_exists($key, $png)) {
                $png[$key] = self::webpToPng($bytes);
            }
            if ($png[$key] === null) {
                return null;
            }
            [$bytes, $mime] = [$png[$key], 'image/png'];
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private static function webpToPng(string $webp): ?string
    {
        if (! function_exists('imagecreatefromwebp') || ! (gd_info()['WebP Support'] ?? false)) {
            return null;
        }
        $im = @imagecreatefromstring($webp);
        if ($im === false) {
            return null;
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        ob_start();
        $ok = imagepng($im, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $ok && $png !== '' ? $png : null;
    }

    /**
     * @param  string  $issuerType  INSURER | BROKER | PLATFORM
     * @param  ?array{name: string, licence?: ?string}  $intermediary
     * @return array{issuer: array<string, mixed>, cobrand: ?array<string, mixed>, footer_lines: list<string>, color: ?string, snapshot: array<string, mixed>}
     */
    public static function forDocument(string $issuerType, string $issuerName, ?string $carrierId, ?string $carrierName, ?string $tenantId, ?array $intermediary): array
    {
        $issuerAsset = match ($issuerType) {
            'BROKER' => self::current('TENANT', $tenantId),
            'PLATFORM' => self::current('TENANT', self::platformTenantId()),
            default => self::current('CARRIER', $carrierId),
        };
        $cobrand = null;
        if ($issuerType === 'BROKER' && $carrierName) {
            $cobrand = ['role' => 'INSURER', 'name' => $carrierName, 'asset' => self::current('CARRIER', $carrierId)];
        } elseif ($intermediary && $issuerType !== 'BROKER') {
            $cobrand = ['role' => 'INTERMEDIARY', 'name' => $intermediary['name'], 'licence' => $intermediary['licence'] ?? null, 'asset' => self::current('TENANT', $tenantId)];
        }

        // Fallback: an insurer / broker without its own artwork keeps its name as a wordmark, and the document carries the
        // OpesInsure platform letterhead mark ("issued through") — never the platform logo in place of the issuer's.
        $platform = null;
        if ($issuerType !== 'PLATFORM' && ($issuerAsset === null || (self::dataUri($issuerAsset, 'logo') === null && self::dataUri($issuerAsset, 'header') === null))) {
            $platformAsset = self::current('TENANT', self::platformTenantId());
            $platform = ['name' => 'OpesInsure', 'logo' => self::dataUri($platformAsset, 'logo')];
        }

        return [
            'platform' => $platform,
            'issuer' => ['name' => $issuerName, 'logo' => self::dataUri($issuerAsset, 'logo'), 'header' => self::dataUri($issuerAsset, 'header')],
            'cobrand' => $cobrand ? ['role' => $cobrand['role'], 'name' => $cobrand['name'], 'licence' => $cobrand['licence'] ?? null, 'logo' => self::dataUri($cobrand['asset'], 'logo')] : null,
            'footer_lines' => $issuerAsset?->footerLines() ?? [],
            'color' => $issuerAsset?->brand_color,
            'snapshot' => ['issuer' => self::provenance($issuerAsset), 'cobrand' => $cobrand ? self::provenance($cobrand['asset']) : null],
        ];
    }

    /** Letterhead of a policy document rendered on behalf of its insurer (legacy certificate / schedule / receipt views). */
    public static function forPolicy(Policy $policy): array
    {
        $policy->loadMissing(['carrier.party', 'tenant']);
        $carrierName = $policy->carrier?->party?->display_name ?? 'Insurer';
        $broker = $policy->tenant?->type === 'BROKER' ? ['name' => $policy->tenant->legal_name, 'licence' => $policy->tenant->getAttributes()['licence_number'] ?? null] : null;

        return self::forDocument('INSURER', $carrierName, $policy->carrier_id, $carrierName, $policy->tenant_id, $broker);
    }

    /** @return ?array<string, mixed> Frozen reference to the artwork version used on an issued document. */
    public static function provenance(?LetterheadAsset $a): ?array
    {
        return $a ? ['letterhead_id' => $a->id, 'owner_type' => $a->owner_type, 'version' => $a->version,
            'logo_sha256' => $a->logo_sha256, 'header_sha256' => $a->header_sha256] : null;
    }

    private static function platformTenantId(): ?string
    {
        return Tenant::where('type', 'PLATFORM')->orderBy('created_at')->value('id');
    }
}
