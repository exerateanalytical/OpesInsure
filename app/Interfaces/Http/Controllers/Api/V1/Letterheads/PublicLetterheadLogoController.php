<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Letterheads;

use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Models\Letterhead\LetterheadAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** GET /api/v1/public/letterheads/{asset}/logo: an ACTIVE, public-display logo only (404 otherwise). */
final class PublicLetterheadLogoController
{
    public function __invoke(string $asset): Response
    {
        abort_unless(Str::isUuid($asset), 404);
        $a = LetterheadAsset::find($asset);
        abort_unless($a && LetterheadResolver::publicLogoUrl($a) !== null, 404);
        $disk = Storage::disk(LetterheadResolver::disk());
        abort_unless($disk->exists($a->logo_path), 404);

        return response($disk->get($a->logo_path), 200, [
            'Content-Type' => $a->logo_mime,
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => '"'.$a->logo_sha256.'"',
            'X-Content-Type-Options' => 'nosniff',
            // SVGs are sanitized on upload; the CSP keeps any residual script inert when opened directly.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
