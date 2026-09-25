<?php

declare(strict_types=1);

namespace App\Application\Documents\Letterhead;

use App\Application\Audit\AuditWriter;
use App\Models\Letterhead\LetterheadAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin-uploaded letterhead artwork for insurers (CARRIER) and organisations (TENANT).
 * Owner rule: only licensed/authorized logos; each version records who authorized it, when and the
 * source. Nothing is scraped or seeded. Every upload is a new immutable version (audited); with
 * maker-checker on (config letterheads.maker_checker) it waits for a second admin.
 */
final class LetterheadService
{
    public const OWNERS = ['CARRIER', 'TENANT'];

    public const TEXT_FIELDS = ['brand_color', 'registered_address', 'rccm', 'niu', 'licence_reference', 'public_display', 'authorized_by', 'authorized_on', 'authorization_source', 'authorization_note'];

    public function __construct(private readonly AuditWriter $audit) {}

    /**
     * @param  array<string, mixed>  $data  TEXT_FIELDS
     * @param  ?string  $logo  new logo bytes (null keeps the current logo unless $removeLogo)
     * @param  ?string  $header  new header image bytes (null keeps the current one unless $removeHeader)
     */
    public function publish(string $ownerType, string $ownerId, array $data, ?string $logo, ?string $header, ?User $actor, bool $removeLogo = false, bool $removeHeader = false): LetterheadAsset
    {
        if (! in_array($ownerType, self::OWNERS, true)) {
            throw ValidationException::withMessages(['owner_type' => 'Unknown letterhead owner type.']);
        }
        $data = $this->validateFields($data);
        $files = ['logo' => $logo !== null ? $this->inspect($logo, 'logo') : null, 'header' => $header !== null ? $this->inspect($header, 'header') : null];
        $remove = ['logo' => $removeLogo, 'header' => $removeHeader];
        $makerChecker = (bool) config('letterheads.maker_checker');

        $asset = DB::transaction(function () use ($ownerType, $ownerId, $data, $files, $remove, $actor, $makerChecker) {
            $col = $ownerType === 'CARRIER' ? 'carrier_id' : 'tenant_id';
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['letterhead:'.$ownerType.':'.$ownerId]);
            $current = LetterheadResolver::current($ownerType, $ownerId);
            $version = (int) LetterheadAsset::where('owner_type', $ownerType)->where($col, $ownerId)->max('version') + 1;
            $asset = new LetterheadAsset($data + ['owner_type' => $ownerType, $col => $ownerId, 'version' => $version,
                'status' => $makerChecker ? 'PENDING_APPROVAL' : 'ACTIVE', 'uploaded_by' => $actor?->id]);
            $asset->id = (string) Str::uuid();
            foreach ($files as $kind => $file) {
                if ($file) {
                    $key = 'letterheads/'.strtolower($ownerType).'/'.$ownerId.'/v'.$version.'-'.$kind.'-'.substr($file['sha256'], 0, 12).'.'.$file['ext'];
                    Storage::disk(LetterheadResolver::disk())->put($key, $file['bytes']);
                    $asset->forceFill([$kind.'_path' => $key, $kind.'_mime' => $file['mime'], $kind.'_sha256' => $file['sha256']]);
                    if ($kind === 'logo') {
                        $asset->forceFill(['logo_width' => $file['width'], 'logo_height' => $file['height']]);
                    }
                } elseif ($current && ! $remove[$kind]) {
                    // Carry the approved artwork forward (same file, same hash).
                    $asset->forceFill([$kind.'_path' => $current->{$kind.'_path'}, $kind.'_mime' => $current->{$kind.'_mime'}, $kind.'_sha256' => $current->{$kind.'_sha256'}]);
                    if ($kind === 'logo') {
                        $asset->forceFill(['logo_width' => $current->logo_width, 'logo_height' => $current->logo_height]);
                    }
                }
            }
            if (! $makerChecker) {
                $asset->forceFill(['approved_by' => $actor?->id, 'approved_at' => now()]);
                $this->supersede($ownerType, $ownerId);
            }
            $asset->save();

            return $asset;
        });

        $this->audit->record('letterhead.version.created', 'letterhead_asset', $asset->id, [
            'owner_type' => $ownerType, 'owner_id' => $ownerId, 'version' => $asset->version, 'status' => $asset->status,
            'logo_sha256' => $asset->logo_sha256, 'header_sha256' => $asset->header_sha256, 'public_display' => $asset->public_display,
            'authorized_by' => $asset->authorized_by, 'authorized_on' => $asset->authorized_on?->toDateString(), 'authorization_source' => $asset->authorization_source,
        ], $asset->authorization_note);
        self::forgetPublicCache();

        return $asset;
    }

    public function approve(LetterheadAsset $asset, User $approver): LetterheadAsset
    {
        if ($asset->status !== 'PENDING_APPROVAL') {
            throw ValidationException::withMessages(['status' => 'Only a pending letterhead version can be approved.']);
        }
        if ($asset->uploaded_by !== null && $asset->uploaded_by === $approver->id) {
            throw ValidationException::withMessages(['approved_by' => 'Maker-checker: the uploader cannot approve their own letterhead.']);
        }
        DB::transaction(function () use ($asset, $approver) {
            $this->supersede($asset->owner_type, (string) ($asset->carrier_id ?? $asset->tenant_id));
            $asset->forceFill(['status' => 'ACTIVE', 'approved_by' => $approver->id, 'approved_at' => now()])->save();
        });
        $this->audit->record('letterhead.version.approved', 'letterhead_asset', $asset->id, ['version' => $asset->version]);
        self::forgetPublicCache();

        return $asset;
    }

    public function reject(LetterheadAsset $asset, User $actor, string $reason): LetterheadAsset
    {
        if ($asset->status !== 'PENDING_APPROVAL') {
            throw ValidationException::withMessages(['status' => 'Only a pending letterhead version can be rejected.']);
        }
        $asset->forceFill(['status' => 'REJECTED'])->save();
        $this->audit->record('letterhead.version.rejected', 'letterhead_asset', $asset->id, ['version' => $asset->version, 'by' => $actor->id], $reason);

        return $asset;
    }

    /** The website provider directory caches its rows (PublicProviderDirectory). */
    private static function forgetPublicCache(): void
    {
        rescue(fn () => \Illuminate\Support\Facades\Cache::forget('public_site.directory.all'), null, false);
    }

    private function supersede(string $ownerType, string $ownerId): void
    {
        LetterheadAsset::where('owner_type', $ownerType)->where($ownerType === 'CARRIER' ? 'carrier_id' : 'tenant_id', $ownerId)
            ->where('status', 'ACTIVE')->update(['status' => 'SUPERSEDED', 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function validateFields(array $data): array
    {
        $v = validator($data, [
            'brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'registered_address' => ['nullable', 'string', 'max:500'],
            'rccm' => ['nullable', 'string', 'max:64'],
            'niu' => ['nullable', 'string', 'max:64'],
            'licence_reference' => ['nullable', 'string', 'max:120'],
            'public_display' => ['nullable', 'boolean'],
            'authorized_by' => ['required', 'string', 'max:190'],
            'authorized_on' => ['required', 'date', 'before_or_equal:today'],
            'authorization_source' => ['required', 'string', 'max:255'],
            'authorization_note' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $out = [];
        foreach (self::TEXT_FIELDS as $f) {
            $val = $v[$f] ?? null;
            $out[$f] = is_string($val) ? (trim($val) === '' ? null : trim($val)) : $val;
        }
        $out['public_display'] = (bool) ($out['public_display'] ?? false);
        $out['brand_color'] = $out['brand_color'] ? strtoupper($out['brand_color']) : null;

        return $out;
    }

    /** @return array{bytes: string, mime: string, ext: string, sha256: string, width: ?int, height: ?int} */
    public function inspect(string $bytes, string $kind): array
    {
        $rules = (array) config('letterheads.'.$kind);
        $fail = function (string $m) use ($kind): never {
            throw ValidationException::withMessages([$kind => $m]);
        };
        if ($bytes === '') {
            $fail('The '.$kind.' file is empty.');
        }
        if (strlen($bytes) > $rules['max_kb'] * 1024) {
            $fail('The '.$kind.' must not exceed '.$rules['max_kb'].' KB.');
        }
        $head = ltrim(substr($bytes, 0, 4096));
        if (str_starts_with($head, '<') && str_contains($head, '<svg')) {
            // Plain SVG only: it is embedded in PDFs and may be served publicly.
            if (preg_match('/<script|<foreignObject|\bon[a-z]+\s*=|javascript:|<!ENTITY|<!DOCTYPE|<iframe|<embed|<object|href\s*=\s*["\'](?!#)/i', $bytes)) {
                $fail('The SVG '.$kind.' contains scripts, event handlers, entities or external references; upload a plain SVG.');
            }
            [$w, $h] = self::svgSize($bytes);

            return ['bytes' => $bytes, 'mime' => 'image/svg+xml', 'ext' => 'svg', 'sha256' => hash('sha256', $bytes), 'width' => $w, 'height' => $h];
        }
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? ($info['mime'] ?? null) : null;
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            $fail('The '.$kind.' must be a PNG, JPG or SVG image.');
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w < $rules['min_width'] || $h < $rules['min_height'] || $w > $rules['max_width'] || $h > $rules['max_height']) {
            $fail(sprintf('The %s must be between %dx%d and %dx%d pixels (got %dx%d).', $kind, $rules['min_width'], $rules['min_height'], $rules['max_width'], $rules['max_height'], $w, $h));
        }

        return ['bytes' => $bytes, 'mime' => $mime, 'ext' => $mime === 'image/png' ? 'png' : 'jpg', 'sha256' => hash('sha256', $bytes), 'width' => $w, 'height' => $h];
    }

    /** @return array{0: ?int, 1: ?int} */
    private static function svgSize(string $svg): array
    {
        if (preg_match('/<svg[^>]*\bviewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $svg, $m)) {
            return [(int) round((float) $m[1]), (int) round((float) $m[2])];
        }
        $w = preg_match('/<svg[^>]*\bwidth\s*=\s*["\']([\d.]+)/i', $svg, $a) ? (int) round((float) $a[1]) : null;
        $h = preg_match('/<svg[^>]*\bheight\s*=\s*["\']([\d.]+)/i', $svg, $b) ? (int) round((float) $b[1]) : null;

        return [$w, $h];
    }
}
