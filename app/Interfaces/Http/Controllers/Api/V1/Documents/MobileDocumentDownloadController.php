<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Documents;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The route MobileDocumentService's bound SignedUrlAdapter points to.
 * Deliberately outside the auth:api/tenant/json.api group (see
 * routes/api.php) — the whole point of a signed URL is that possessing it,
 * un-expired and with a valid signature, IS the authorization, exactly
 * like Laravel's own signed email-verification links. The 'signed'
 * middleware already rejects a missing/invalid/expired signature before
 * this ever runs; the scan_status recheck here is defense in depth against
 * a document being reclassified INFECTED after a link was already issued
 * but before it expired.
 */
final class MobileDocumentDownloadController
{
    public function __invoke(string $document): StreamedResponse
    {
        $record = Document::where('id', $document)->where('scan_status', 'CLEAN')->first();

        abort_unless($record, 404);

        $disk = Storage::disk(config('filesystems.default'));

        abort_unless($disk->exists($record->storage_key), 404);

        return $disk->download($record->storage_key, null, ['Content-Type' => $record->mime_type]);
    }
}
