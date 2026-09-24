<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Application\Audit\AuditWriter;
use App\Models\Document;
use App\Models\DocumentPackManifest;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Adaptive Mode 1 — carrier original documents. The insurer keeps its own
 * policy, attestation, cover note, endorsement, receipt, conditions and claim
 * documents; OpesInsure stores, indexes, verifies (by code) and delivers them.
 * Each keeps issuer, policy, type, issue date, the carrier's own number and
 * version, uploaded_by, sha256 and an audit trail. An uploaded original is the
 * official document for its type (and subject): a platform-rendered document
 * of the same kind becomes REPLACED, and any pack item that was
 * AWAITING_CARRIER_DOCUMENT for it becomes CARRIER_PROVIDED.
 */
final class CarrierDocumentService
{
    public const MIME = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(private DocumentRegister $register, private DocumentEngine $engine, private AuditWriter $audit) {}

    /**
     * @param array{document_type_code: string, issue_date: string, carrier_document_number?: ?string, carrier_version?: ?string, language?: ?string, subject_key?: ?string, subject_label?: ?string, valid_until?: ?string, issuer?: ?string, claim_id?: ?string} $meta
     */
    public function upload(Policy $policy, string $bytes, string $mime, array $meta, User $actor): Document
    {
        $type = $this->register->type((string) ($meta['document_type_code'] ?? ''));
        $errors = [];
        if (! $type) {
            $errors['document_type_code'] = 'Unknown document type (not in the Canonical Document Register).';
        } elseif ($type['input_document']) {
            $errors['document_type_code'] = 'Customer/underwriting input documents are evidence, not carrier originals.';
        }
        if (! in_array($mime, self::MIME, true)) {
            $errors['file'] = 'Carrier originals must be PDF, JPEG or PNG.';
        }
        if ($bytes === '' || strlen($bytes) > 20 * 1024 * 1024) {
            $errors['file'] = 'File missing or larger than 20 MB.';
        }
        if (empty($meta['issue_date'])) {
            $errors['issue_date'] = 'The carrier issue date is required.';
        }
        if (! in_array($meta['language'] ?? 'FR', ['FR', 'EN', 'BILINGUAL'], true)) {
            $errors['language'] = 'Language must be FR, EN or BILINGUAL.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $issuer = ($meta['issuer'] ?? 'INSURER') === 'BROKER' ? 'BROKER' : 'INSURER';

        return DB::transaction(function () use ($policy, $bytes, $mime, $meta, $actor, $type, $issuer): Document {
            $policy = Policy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', $bytes);
            if (Document::where('policy_id', $policy->id)->where('sha256', $hash)->where('is_carrier_original', true)->exists()) {
                throw ValidationException::withMessages(['file' => 'This exact carrier document is already on file.']);
            }
            $ext = $mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : 'jpg');
            $key = 'documents/'.$policy->tenant_id.'/'.$policy->id.'/carrier-'.$type['code'].'-'.substr($hash, 0, 16).'.'.$ext;
            Storage::disk((string) config('lifecycle.documents_disk', 'local'))->put($key, $bytes);
            $certificateLike = $type['display_group'] === 'CERTIFICATES';

            $doc = Document::create([
                'tenant_id' => $policy->tenant_id, 'party_id' => $policy->party_id, 'policy_id' => $policy->id, 'category' => 'CARRIER_'.$type['code'],
                'storage_key' => $key, 'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'sha256' => $hash,
                'scan_status' => 'CLEAN', 'verification_status' => 'VERIFIED', 'ocr_data' => [],
                'document_type_code' => $type['code'], 'document_type_id' => $type['id'], 'document_group' => $type['group_code'],
                'policy_version' => (int) $policy->version, 'claim_id' => $meta['claim_id'] ?? null,
                'subject_type' => ! empty($meta['subject_key']) ? 'VEHICLE' : null, 'subject_key' => $meta['subject_key'] ?? null, 'subject_label' => $meta['subject_label'] ?? ($meta['subject_key'] ?? null),
                'title' => $type['name_en'], 'issuer_type' => $issuer, 'issuer_carrier_id' => $policy->carrier_id, 'issuer_tenant_id' => $policy->tenant_id,
                'language' => $meta['language'] ?? 'FR', 'document_origin' => $issuer, 'document_stage' => $this->stageOf($type),
                'security_level' => $type['security_level'], 'status' => $certificateLike ? 'VALID' : 'ISSUED',
                'verification_code' => DocumentEngine::newVerificationCode(), 'generation_trigger' => 'CARRIER_UPLOAD',
                'issued_at' => $meta['issue_date'], 'valid_from' => $certificateLike ? $policy->coverage_starts_at : null,
                'valid_until' => $meta['valid_until'] ?? ($certificateLike ? $policy->coverage_ends_at : null),
                'is_carrier_original' => true, 'uploaded_by' => $actor->id,
                'provenance' => ['source' => 'CARRIER_ORIGINAL', 'carrier_document_number' => $meta['carrier_document_number'] ?? null, 'carrier_version' => $meta['carrier_version'] ?? null,
                    'issue_date' => $meta['issue_date'], 'uploaded_by' => $actor->id, 'uploaded_at' => now()->toIso8601String(), 'sha256' => $hash],
            ]);

            $this->engine->supersedePrevious($policy, $doc, $actor, 'REPLACED');
            $this->fillAwaitingItems($policy, $doc);
            $this->audit->record('document.carrier_original.uploaded', 'document', $doc->id, ['policy_id' => $policy->id, 'type' => $type['code'], 'sha256' => $hash, 'carrier_document_number' => $meta['carrier_document_number'] ?? null]);

            return $doc->refresh();
        });
    }

    private function fillAwaitingItems(Policy $policy, Document $doc): void
    {
        $manifests = DocumentPackManifest::where('policy_id', $policy->id)->orderByDesc('generated_at')->lockForUpdate()->get();
        foreach ($manifests as $manifest) {
            $changed = false;
            $items = $manifest->items;
            foreach ($items as &$item) {
                if (in_array($item['document_type_code'], DocumentEngine::kindCodes((string) $doc->document_type_code), true)
                    && ($item['subject_key'] ?? null) === $doc->subject_key
                    && in_array($item['state'], ['AWAITING_CARRIER_DOCUMENT', 'TEMPLATE_MISSING', 'GENERATED'], true)) {
                    $item['state'] = 'CARRIER_PROVIDED';
                    $item['document_id'] = $doc->id;
                    $changed = true;
                }
            }
            unset($item);
            if ($changed) {
                $manifest->update(['items' => $items]);
                $doc->forceFill(['pack_manifest_id' => $doc->pack_manifest_id ?? $manifest->id, 'pack_code' => $doc->pack_code ?? $manifest->pack_code])->save();

                return; // latest event that needed it
            }
        }
    }

    private function stageOf(array $type): string
    {
        return match ($type['display_group']) {
            'CLAIMS' => 'CLAIM',
            'FINANCIAL' => 'FINANCE',
            'SERVICING' => 'SERVICING',
            default => 'POLICY',
        };
    }
}
