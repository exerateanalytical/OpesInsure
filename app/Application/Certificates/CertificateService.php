<?php

declare(strict_types=1);

namespace App\Application\Certificates;

use App\Application\Audit\AuditWriter;
use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Engine\DocumentNumberAllocator;
use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Events\OutboxWriter;
use App\Models\CertificateTemplate;
use App\Models\Document;
use App\Models\Policy;
use App\Models\PolicyCertificate;
use App\Models\StickerBatch;
use App\Models\StickerStock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-DUP-005: certificates and attestations are DOCUMENT TYPES of the one
 * document engine (catalogue display group CERTIFICATES: MOTOR_INSURANCE_
 * CERTIFICATE, CERTIFICATE_OF_INSURANCE, PROOF_OF_COVER …). A certificate
 * issued here is a canonical `documents` row: engine number (allocator),
 * verification code, status lifecycle (supersede / revoke via
 * DocumentStatusService). certificate_templates and policy_certificates are
 * read-only history: no new template is created or approved here (templates
 * are document_templates, DocumentTemplateService) and no new
 * policy_certificates row is written. Legacy certificates still verify and may
 * still be voided (a status change on history, never a delete).
 *
 * Sticker stock and custody remain here (physical inventory, not documents).
 */
final class CertificateService
{
    public const DEFAULT_TYPE = 'CERTIFICATE_OF_INSURANCE';

    public const MOTOR_TYPE = 'MOTOR_INSURANCE_CERTIFICATE';

    public function __construct(
        private AuditWriter $audit,
        private OutboxWriter $outbox,
        private DocumentRegister $register,
        private DocumentNumberAllocator $numbers,
        private DocumentEngine $engine,
    ) {}

    public function createTemplate(array $d, User $actor): CertificateTemplate
    {
        throw ValidationException::withMessages(['template' => 'Certificate templates are read-only history. Create a document template for a certificate document type instead (document templates).']);
    }

    public function approveTemplate(CertificateTemplate $t, User $actor): CertificateTemplate
    {
        throw ValidationException::withMessages(['template' => 'Certificate templates are read-only history. Approve document templates instead.']);
    }

    public function receiveBatch(array $d, User $actor): StickerBatch
    {
        return DB::transaction(function () use ($d, $actor): StickerBatch {
            $b = StickerBatch::create([...$d, 'quantity' => count($d['stickers']), 'received_by' => $actor->id, 'received_at' => now(), 'status' => 'RECEIVED']);
            $level = ! empty($d['custodian_tenant_id']) ? 'BROKER' : 'CARRIER';
            foreach ($d['stickers'] as $s) {
                $stock = StickerStock::create(['serial_number' => $s['serial_number'], 'carrier_id' => $d['carrier_id'], 'batch_number' => $d['batch_number'], 'status' => 'IN_STOCK',
                    'custodian_tenant_id' => $d['custodian_tenant_id'] ?? null, 'sticker_batch_id' => $b->id, 'security_code_hash' => hash('sha256', $s['security_code']), 'custody_level' => $level]);
                // REQ-POL-007: the custody chain starts at receipt.
                DB::table('sticker_custody_events')->insert(['id' => (string) Str::uuid(), 'sticker_stock_id' => $stock->id, 'event_type' => 'RECEIVED', 'from_tenant_id' => null,
                    'to_tenant_id' => $d['custodian_tenant_id'] ?? null, 'to_level' => $level, 'actor_id' => $actor->id, 'reason_code' => 'BATCH_RECEIVED', 'occurred_at' => now()]);
            }
            $this->audit->record('sticker.batch.received', 'sticker_batch', $b->id, ['quantity' => $b->quantity]);

            return $b;
        });
    }

    /** Catalogue certificate type for an issue request (explicit code, else legacy template type, else generic). */
    public function typeFor(?string $code, ?CertificateTemplate $legacyTemplate = null): array
    {
        $code ??= $legacyTemplate && str_contains(strtoupper((string) $legacyTemplate->type), 'MOTOR') ? self::MOTOR_TYPE : self::DEFAULT_TYPE;
        $type = $this->register->type($code);
        if (! $type || $type['display_group'] !== 'CERTIFICATES' || $type['input_document']) {
            throw ValidationException::withMessages(['document_type_code' => 'Not a certificate/attestation document type of the catalogue.']);
        }

        return $type;
    }

    /**
     * Registers an externally rendered/printed certificate (its sha256 is
     * supplied) as the policy's current engine document of that type.
     *
     * @param  array{serial_number: string, document_hash: string, document_type_code?: ?string, sticker_serial_number?: ?string}  $d
     * @return array{certificate: Document, verification_token: string}
     */
    public function issue(Policy $p, ?CertificateTemplate $legacyTemplate, array $d, User $actor): array
    {
        $type = $this->typeFor($d['document_type_code'] ?? null, $legacyTemplate);

        return DB::transaction(function () use ($p, $legacyTemplate, $d, $actor, $type): array {
            $p = Policy::whereKey($p->id)->lockForUpdate()->firstOrFail();
            if (! in_array($p->status, ['ACTIVE', 'EXPIRING'], true)) {
                throw ValidationException::withMessages(['status' => __('wave5.active_policy_template_required')]);
            }
            if (strlen($d['document_hash']) !== 64 || ! ctype_xdigit($d['document_hash'])) {
                throw ValidationException::withMessages(['document_hash' => __('wave5.document_hash_invalid')]);
            }
            $serial = (string) $d['serial_number'];
            if (PolicyCertificate::where('serial_number', $serial)->exists() || Document::whereRaw("provenance->>'certificate_serial' = ?", [$serial])->exists()) {
                throw ValidationException::withMessages(['serial_number' => 'This certificate serial number is already on record.']);
            }

            $token = Str::random(64);
            $number = $this->numbers->allocate($p->tenant_id, $type['code']);
            $verification = DocumentEngine::newVerificationCode();
            [$key, $bytes] = $this->render($p, $type, $number['number'], $verification, $serial);
            $doc = Document::create([
                'tenant_id' => $p->tenant_id, 'party_id' => $p->party_id, 'policy_id' => $p->id, 'category' => 'CERT_'.$type['code'],
                'storage_key' => $key, 'mime_type' => 'application/pdf', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                'scan_status' => 'CLEAN', 'verification_status' => 'VERIFIED', 'ocr_data' => [],
                'document_type_code' => $type['code'], 'document_type_id' => $type['id'] ?? null, 'document_group' => $type['group_code'] ?? null,
                'policy_version' => (int) $p->version, 'title' => $type['name_en'], 'issuer_type' => 'INSURER', 'issuer_carrier_id' => $p->carrier_id, 'issuer_tenant_id' => $p->tenant_id,
                'document_origin' => 'INSURER', 'document_stage' => 'POLICY', 'security_level' => $type['security_level'], 'status' => 'VALID',
                'numbering_family' => $number['family'], 'document_number' => $number['number'], 'document_sequence' => $number['sequence'],
                'verification_code' => $verification, 'generation_trigger' => 'CERTIFICATE_ISSUE', 'issued_at' => now(),
                'valid_from' => $p->coverage_starts_at, 'valid_until' => $p->coverage_ends_at, 'uploaded_by' => $actor->id,
                'provenance' => ['source' => 'CERTIFICATE_ISSUE', 'certificate_serial' => $serial, 'verification_token_hash' => hash('sha256',$token),
                    'legacy_certificate_template_id' => $legacyTemplate?->id, 'sticker_serial_number' => $d['sticker_serial_number'] ?? null, 'external_document_hash' => strtolower($d['document_hash']), 'rendered_by' => 'OPESINSURE'],
            ]);
            $this->engine->supersedePrevious($p, $doc, $actor);

            if (! empty($d['sticker_serial_number'])) {
                // REQ-POL-007: one assign-to-policy path (custody checks + sticker_custody_events ASSIGNED_TO_POLICY).
                app(\App\Application\Stickers\StickerCustodyService::class)->assignToPolicy($p, (string) $d['sticker_serial_number'], $actor);
            }
            $this->audit->record('certificate.issued', 'document', $doc->id, ['policy_id' => $p->id, 'document_number' => $doc->document_number, 'type' => $type['code']]);
            $this->outbox->record('certificate.issued', 'document', $doc->id, ['document_id' => $doc->id, 'policy_id' => $p->id]);

            return ['certificate' => $doc->refresh(), 'verification_token' => $token];
        });
    }

    /**
     * Serial + token verification. REQ-DUP-015: delegates to the one public
     * verification service (engine certificate documents first, then legacy
     * policy_certificates history). The fingerprint is only ever stored hashed
     * (public_verification_lookups / certificate_verification_events.request_fingerprint_hash).
     *
     * @return array{serial_number: string, policy: Policy, issued_at: mixed, document_hash: string, document_id: ?string}
     */
    public function verify(string $serial, string $token, string $fingerprint): array
    {
        return app(\App\Application\Documents\Verification\PublicVerificationService::class)->verifyCertificate($serial, $token, $fingerprint);
    }

    /** Voiding a LEGACY certificate (history row status only). Engine certificates are revoked via DocumentStatusService (maker-checker). */
    public function void(PolicyCertificate $c, string $reason, User $actor): PolicyCertificate
    {
        if ($c->status !== 'VALID') {
            throw ValidationException::withMessages(['status' => __('wave5.certificate_not_valid')]);
        }
        $c->update(['status' => 'VOID', 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
        $this->audit->record('certificate.voided', 'policy_certificate', $c->id, [], 'CERTIFICATE_VOIDED');

        return $c->refresh();
    }

    /**
     * Renders the certificate PDF with the engine's document view (same
     * layout, QR and verification code as engine-generated documents) and
     * stores it on the documents disk, so the signed download serves it.
     *
     * @return array{0: string, 1: string} storage key, PDF bytes
     */
    private function render(Policy $p, array $type, string $number, string $verification, string $serial): array
    {
        $p->loadMissing(['carrier.party', 'party', 'proposal.offer.product']);
        $verifyUrl = rtrim((string) config('lifecycle.verify_url'), '/').'?code='.$verification;
        $qr = (new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG, 'outputBase64' => true, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M, 'addQuietzone' => true])))->render($verifyUrl);
        $carrier = $p->carrier?->party?->display_name ?? 'Insurer';
        $bytes = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.engine-document', [
            'lang' => 'BILINGUAL', 'titleEn' => $type['name_en'], 'titleFr' => $type['name_fr'] ?? $type['name_en'], 'documentNumber' => $number,
            'issuerName' => $carrier, 'intermediary' => null, 'carrierName' => $carrier, 'policyNumber' => $p->policy_number, 'policyVersion' => (int) $p->version,
            'insuredName' => $p->party?->display_name ?? '', 'productName' => $p->proposal?->offer?->product?->name, 'subjectLabel' => null,
            'validFrom' => $p->coverage_starts_at?->format('d/m/Y'), 'validUntil' => $p->coverage_ends_at?->format('d/m/Y'),
            'eventLabel' => 'CERTIFICATE '.$serial, 'issuedAt' => now()->format('d/m/Y H:i'), 'verificationCode' => $verification, 'qr' => $qr, 'verifyUrl' => $verifyUrl,
            'sections' => [], 'coverages' => [], 'signatory' => null, 'templateRef' => 'Certificate serial '.$serial,
        ])->setPaper('a4')->output();
        $key = 'documents/'.$p->tenant_id.'/'.$p->id.'/'.$number.'.pdf';
        \Illuminate\Support\Facades\Storage::disk((string) config('lifecycle.documents_disk', 'local'))->put($key, $bytes);

        return [$key, $bytes];
    }
}
