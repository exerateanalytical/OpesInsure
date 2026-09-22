<?php

declare(strict_types=1);

namespace App\Application\Documents\Adapters;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;

/**
 * Real implementation for when FILESYSTEM_DISK=s3 (config/filesystems.php
 * already has a full S3-compatible disk defined — AWS_* env vars, MinIO in
 * this stack's docker-compose). Delegates to the disk's own native
 * presigned URL rather than building anything bespoke: this is what
 * "prefer the cloud storage adapter's native presigned URLs" means in
 * practice. Not the active binding in this environment today (see
 * AppServiceProvider) because FILESYSTEM_DISK=local in .env, but it exists
 * so switching disks is a config change, not a rewrite.
 */
final class S3SignedUrlAdapter implements SignedUrlAdapter
{
    public function sign(Document $document, int $ttlSeconds): SignedUrl
    {
        $expiresAt = now()->addSeconds($ttlSeconds);

        $url = Storage::disk('s3')->temporaryUrl($document->storage_key, $expiresAt);

        return new SignedUrl($url, $expiresAt->toIso8601String());
    }
}
