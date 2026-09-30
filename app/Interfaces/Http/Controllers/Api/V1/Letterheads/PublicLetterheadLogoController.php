<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Letterheads;

use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Interfaces\Http\Middleware\PublicCacheable;
use App\Models\Letterhead\LetterheadAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/public/letterheads/{asset}/logo: an ACTIVE, public-display logo only (404 otherwise).
 * Requested with its content hash (?v=, as LetterheadResolver::publicLogoUrl builds it) the bytes
 * can never change, so it is cacheable for a year; without (or with a stale) v it is not cached.
 */
final class PublicLetterheadLogoController
{
    public function __invoke(Request $request, string $asset): Response
    {
        abort_unless(Str::isUuid($asset), 404);
        $a = LetterheadAsset::find($asset);
        abort_unless($a && LetterheadResolver::publicLogoUrl($a) !== null, 404);
        $disk = Storage::disk(LetterheadResolver::disk());
        abort_unless($disk->exists($a->logo_path), 404);

        $v = (string) $request->query('v', '');
        $immutable = $v !== '' && $a->logo_sha256 && hash_equals(substr((string) $a->logo_sha256, 0, 12), $v);
        if ($immutable) {
            PublicCacheable::mark($request);
        }

        return response($disk->get($a->logo_path), 200, [
            'Content-Type' => $a->logo_mime,
            'Cache-Control' => $immutable ? PublicCacheable::IMMUTABLE : 'no-store, private',
            'ETag' => '"'.$a->logo_sha256.'"',
            'X-Content-Type-Options' => 'nosniff',
            // SVGs are sanitized on upload; the CSP keeps any residual script inert when opened directly.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
