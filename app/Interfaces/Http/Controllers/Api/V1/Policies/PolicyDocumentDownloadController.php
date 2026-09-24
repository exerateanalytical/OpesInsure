<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Policies;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serves an issuance PDF behind a temporary signed URL (see PolicyDocumentService::downloadUrl). */
final class PolicyDocumentDownloadController
{
    public function __invoke(string $document): StreamedResponse
    {
        $record = Document::whereKey($document)->whereNotNull('policy_id')->where('scan_status', 'CLEAN')->first();
        abort_unless($record, 404);

        $disk = Storage::disk((string) config('lifecycle.documents_disk', 'local'));
        abort_unless($disk->exists($record->storage_key), 404);

        // Reuse the existing access log for every signed download (document engine requirement).
        \Illuminate\Support\Facades\DB::table('document_access_log')->insert(['document_id' => $record->id, 'actor_id' => null, 'action' => 'DOWNLOAD', 'purpose' => 'SIGNED_LINK', 'request_id' => (string) (request()->header('X-Request-Id') ?? \Illuminate\Support\Str::uuid()), 'occurred_at' => now()]);

        $name = basename($record->storage_key);

        return $disk->response($record->storage_key, $name, ['Content-Type' => $record->mime_type, 'Cache-Control' => 'private, no-store']);
    }
}
