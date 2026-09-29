<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Audit\AuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stored-document registration and scan/verification review, shared by the API (DocumentController register / review)
 * and the staff desktop (DocumentActions). Callers validate input; this service owns the writes and the audit.
 */
final class DocumentRegistrationService
{
    public function __construct(private readonly AuditWriter $audit) {}

    /**
     * Registers a stored file as a PENDING / UNVERIFIED document with version 1. Refuses (409) an identical checksum.
     *
     * @param  array{party_id?: ?string, category: string, storage_key: string, mime_type: string, size_bytes: int, sha256: string}  $d
     * @return array{id: string, scan_status: string}
     */
    public function register(string $tenantId, array $d, User $actor): array
    {
        abort_if(DB::table('documents')->where('sha256', $d['sha256'])->exists(), 409, 'Identical document already registered.');
        $id = (string) Str::uuid();
        DB::transaction(function () use ($tenantId, $d, $id, $actor) {
            DB::table('documents')->insert([...$d, 'id' => $id, 'tenant_id' => $tenantId, 'scan_status' => 'PENDING', 'verification_status' => 'UNVERIFIED',
                'ocr_data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('document_versions')->insert(['id' => (string) Str::uuid(), 'document_id' => $id, 'version' => 1, 'storage_key' => $d['storage_key'],
                'sha256' => $d['sha256'], 'size_bytes' => $d['size_bytes'], 'mime_type' => $d['mime_type'], 'uploaded_by' => $actor->id, 'created_at' => now()]);
            $this->audit->record('document.registered', 'document', $id, ['category' => $d['category']]);
        });

        return ['id' => $id, 'scan_status' => 'PENDING'];
    }

    /**
     * Records the scan and verification result. An unclean document cannot be verified (422). Stored OCR data is only
     * replaced when the caller supplies ocr_data (null = keep what is stored).
     *
     * @return array{id: string, scan_status: string, verification_status: string}
     */
    public function review(string $tenantId, string $documentId, string $scanStatus, string $verificationStatus, ?array $ocrData): array
    {
        $doc = DB::table('documents')->where('tenant_id', $tenantId)->where('id', $documentId)->first();
        abort_unless($doc, 404);
        abort_if($scanStatus !== 'CLEAN' && $verificationStatus === 'VERIFIED', 422, 'An unclean document cannot be verified.');
        $update = ['scan_status' => $scanStatus, 'verification_status' => $verificationStatus, 'updated_at' => now()];
        if ($ocrData !== null) {
            $update['ocr_data'] = json_encode($ocrData);
        }
        DB::table('documents')->where('id', $documentId)->update($update);
        $this->audit->record('document.reviewed', 'document', $documentId, ['scan_status' => $scanStatus, 'verification_status' => $verificationStatus]);

        return ['id' => $documentId, 'scan_status' => $scanStatus, 'verification_status' => $verificationStatus];
    }
}
