<?php

declare(strict_types=1);

namespace App\Application\Documents\Letterhead;

use App\Models\Letterhead\LetterheadAsset;
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

        return 'data:'.$a->{$kind.'_mime'}.';base64,'.base64_encode($bytes);
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

        return [
            'issuer' => ['name' => $issuerName, 'logo' => self::dataUri($issuerAsset, 'logo'), 'header' => self::dataUri($issuerAsset, 'header')],
            'cobrand' => $cobrand ? ['role' => $cobrand['role'], 'name' => $cobrand['name'], 'licence' => $cobrand['licence'] ?? null, 'logo' => self::dataUri($cobrand['asset'], 'logo')] : null,
            'footer_lines' => $issuerAsset?->footerLines() ?? [],
            'color' => $issuerAsset?->brand_color,
            'snapshot' => ['issuer' => self::provenance($issuerAsset), 'cobrand' => $cobrand ? self::provenance($cobrand['asset']) : null],
        ];
    }

    /** Letterhead of a policy document rendered on behalf of its insurer (legacy certificate / schedule / receipt views). */
    public static function forPolicy(\App\Models\Policy $policy): array
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
