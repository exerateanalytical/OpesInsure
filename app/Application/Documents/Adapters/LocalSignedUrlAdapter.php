<?php

declare(strict_types=1);

namespace App\Application\Documents\Adapters;

use App\Models\Document;
use Illuminate\Support\Facades\URL;

/**
 * Real implementation for the local-disk storage this app actually runs
 * with today (FILESYSTEM_DISK=local — see config/filesystems.php and the
 * main .env). Laravel's temporarySignedRoute is the idiomatic tool for
 * exactly this: an HMAC-signed, expiring URL that needs no separate token
 * table. The target route (mobile.documents.download, registered outside
 * the auth:api group since the signature itself is the authorization) is
 * what actually streams bytes; this adapter only mints the link.
 */
final class LocalSignedUrlAdapter implements SignedUrlAdapter
{
    public function sign(Document $document, int $ttlSeconds): SignedUrl
    {
        $expiresAt = now()->addSeconds($ttlSeconds);

        $url = URL::temporarySignedRoute('mobile.documents.download', $expiresAt, ['document' => $document->id]);

        return new SignedUrl($url, $expiresAt->toIso8601String());
    }
}
