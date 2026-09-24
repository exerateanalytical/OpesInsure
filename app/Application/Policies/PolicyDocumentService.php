<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\CertificateTemplate;
use App\Models\Document;
use App\Models\Policy;
use App\Models\PolicyCertificate;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Issuance documents (audit A6/B12): at carrier approval every policy gets
 *  - a certificate/attestation record (policy_certificates) with a serial
 *    number and a hashed verification token, mirrored onto
 *    policies.certificate_number so the public verify endpoint finds it;
 *  - a certificate PDF and a policy schedule PDF, each carrying a QR code
 *    that opens the public verification page for the serial;
 *  - Document rows (category POLICY_CERTIFICATE / POLICY_SCHEDULE, linked
 *    by documents.policy_id) stored on the local disk and only ever served
 *    through short-lived signed URLs (route mobile.policy-documents.download).
 *
 * ensure() is idempotent and self-healing: the wallet calls it too, so a
 * policy issued before this existed (or whose PDF render failed) gets its
 * documents on first view instead of a dead "Certificate" button.
 */
final class PolicyDocumentService
{
    public const CERTIFICATE = 'POLICY_CERTIFICATE';

    public const SCHEDULE = 'POLICY_SCHEDULE';

    private const SYSTEM_TEMPLATE_CODE = 'OPES_DIGITAL_ATTESTATION';

    public function __construct(private AuditWriter $audit, private OutboxWriter $outbox) {}

    /** @return array{certificate: PolicyCertificate, verification_token: ?string, documents: array<int, Document>} */
    public function ensure(Policy $policy, ?User $actor = null): array
    {
        $policy->loadMissing(['carrier.party', 'party', 'proposal.offer.product', 'proposal.offer.quote']);
        $token = null;
        $certificate = $policy->certificates()->where('status', 'VALID')->latest('issued_at')->first();

        if (! $certificate) {
            $issuer = $actor ?? User::find(\App\Models\PolicyIssuanceRequest::whereKey($policy->issuance_request_id)->value('approved_by')) ?? User::orderBy('created_at')->first();
            [$certificate, $token] = $this->issueCertificate($policy, $issuer);
        }

        $documents = [];
        foreach ([self::CERTIFICATE, self::SCHEDULE] as $category) {
            $doc = Document::where('policy_id', $policy->id)->where('category', $category)->latest('created_at')->first();
            if (! $doc || ! Storage::disk($this->disk())->exists($doc->storage_key)) {
                try {
                    $doc = $this->render($policy, $certificate, $category, $token, $doc);
                } catch (Throwable $e) {
                    report($e);
                    $doc = null;
                }
            }
            if ($doc) {
                $documents[] = $doc;
            }
        }

        return ['certificate' => $certificate, 'verification_token' => $token, 'documents' => $documents];
    }

    public function downloadUrl(Document $document): string
    {
        return URL::temporarySignedRoute('mobile.policy-documents.download', now()->addMinutes((int) config('lifecycle.download_ttl_minutes', 30)), ['document' => $document->id]);
    }

    /** The QR / public page URL: ref + the certificate's verification token (required by GET /verify). */
    public static function verifyUrl(PolicyCertificate $certificate): string
    {
        $url = rtrim((string) config('lifecycle.verify_url'), '/').'?ref='.rawurlencode($certificate->serial_number);
        $token = self::tokenOf($certificate);

        return $token ? $url.'&t='.rawurlencode($token) : $url;
    }

    public static function tokenOf(PolicyCertificate $certificate): ?string
    {
        try {
            return $certificate->verification_token_encrypted ? Crypt::decryptString($certificate->verification_token_encrypted) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function documentsPayload(Policy $policy): array
    {
        return Document::where('policy_id', $policy->id)->whereIn('category', [self::CERTIFICATE, self::SCHEDULE])->orderBy('category')->get()
            ->unique('category')
            ->map(fn (Document $d) => [
                'id' => $d->id,
                'category' => $d->category,
                'type' => $d->category === self::CERTIFICATE ? 'CERTIFICATE' : 'SCHEDULE',
                'title' => $d->category === self::CERTIFICATE ? 'Insurance certificate' : 'Policy schedule',
                'mime_type' => $d->mime_type,
                'size_bytes' => (int) $d->size_bytes,
                'created_at' => $d->created_at?->toIso8601String(),
                'download_url' => $this->downloadUrl($d),
            ])->values()->all();
    }

    /** @return array{0: PolicyCertificate, 1: string} */
    private function issueCertificate(Policy $policy, User $issuer): array
    {
        return DB::transaction(function () use ($policy, $issuer): array {
            $template = $this->template($policy);
            $token = Str::random(64);
            $line = $policy->proposal?->offer?->quote?->line_code ?? $policy->terms_snapshot['line_code'] ?? 'POL';
            do {
                $serial = 'OPS-'.strtoupper(substr($line, 0, 3)).'-'.now()->format('Y').'-'.strtoupper(Str::random(8));
            } while (PolicyCertificate::where('serial_number', $serial)->exists());

            $certificate = PolicyCertificate::create([
                'policy_id' => $policy->id,
                'certificate_template_id' => $template->id,
                'serial_number' => $serial,
                'verification_token_hash' => hash('sha256', $token),
                'verification_token_encrypted' => Crypt::encryptString($token),
                'document_hash' => hash('sha256', $serial.'|'.$policy->id.'|'.$policy->terms_hash),
                'status' => 'VALID',
                'issued_at' => now(),
                'issued_by' => $issuer->id,
            ]);
            if (! $policy->certificate_number) {
                $policy->forceFill(['certificate_number' => $serial])->save();
            }

            $this->audit->record('certificate.issued', 'policy_certificate', $certificate->id, ['policy_id' => $policy->id, 'mode' => 'AUTOMATIC_AT_ISSUANCE']);
            $this->outbox->record('certificate.issued', 'policy_certificate', $certificate->id, ['certificate_id' => $certificate->id, 'policy_id' => $policy->id]);

            return [$certificate, $token];
        });
    }

    private function template(Policy $policy): CertificateTemplate
    {
        $at = $policy->coverage_starts_at?->toDateString() ?? now()->toDateString();
        $existing = CertificateTemplate::where('code', self::SYSTEM_TEMPLATE_CODE)->where('status', 'ACTIVE')
            ->whereDate('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $at))
            ->latest('effective_from')->first();

        if ($existing) {
            return $existing;
        }

        $layout = ['kind' => 'DIGITAL_ATTESTATION', 'qr' => 'VERIFY_URL', 'fields' => ['policy_number', 'serial_number', 'insured', 'carrier', 'product', 'coverage_period']];

        return CertificateTemplate::firstOrCreate(['code' => self::SYSTEM_TEMPLATE_CODE, 'version' => 1], [
            'type' => 'DIGITAL_ATTESTATION', 'status' => 'ACTIVE', 'layout_schema' => $layout,
            'template_hash' => hash('sha256', json_encode($layout)), 'effective_from' => '2020-01-01', 'effective_until' => null,
        ]);
    }

    private function render(Policy $policy, PolicyCertificate $certificate, string $category, ?string $token, ?Document $existing): Document
    {
        $token ??= self::tokenOf($certificate);
        $verifyUrl = self::verifyUrl($certificate);
        $qr = (new QRCode(new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => true,
            'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
            'addQuietzone' => true,
        ])))->render($verifyUrl);

        $product = $policy->proposal?->offer?->product;
        $data = [
            'policy' => $policy,
            'certificate' => $certificate,
            'carrierName' => $policy->carrier?->party?->display_name ?? 'Insurer',
            'insuredName' => $policy->party?->display_name ?? 'Policyholder',
            'productName' => $product?->name ?? 'Insurance cover',
            'lineCode' => $product?->line_code ?? $policy->proposal?->offer?->quote?->line_code,
            'riskFacts' => $policy->proposal?->offer?->quote?->risk_facts ?? [],
            'coverages' => $policy->terms_snapshot['coverage_snapshot']['coverages'] ?? [],
            'terms' => $policy->terms_snapshot ?? [],
            'verifyUrl' => $verifyUrl,
            'verificationToken' => $token,
            'qr' => $qr,
        ];

        $view = $category === self::CERTIFICATE ? 'pdf.policy-certificate' : 'pdf.policy-schedule';
        $bytes = Pdf::loadView($view, $data)->setPaper('a4')->output();
        $key = 'policies/'.$policy->id.'/'.($category === self::CERTIFICATE ? 'certificate' : 'schedule').'-'.$certificate->serial_number.'.pdf';
        Storage::disk($this->disk())->put($key, $bytes);

        $attributes = [
            'tenant_id' => $policy->tenant_id, 'party_id' => $policy->party_id, 'policy_id' => $policy->id,
            'category' => $category, 'storage_key' => $key, 'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            // System-generated, never user-supplied: nothing to scan.
            'scan_status' => 'CLEAN', 'verification_status' => 'VERIFIED', 'ocr_data' => [],
            // Document engine record: platform-rendered proof of cover / schedule (issuer PLATFORM, origin SYSTEM).
            'document_type_code' => $category === self::CERTIFICATE ? 'PROOF_OF_COVER' : 'POLICY_SCHEDULE',
            'document_type_id' => $category === self::CERTIFICATE ? 'DOC-028' : 'DOC-022', 'document_group' => 'CONTRACT',
            'issuer_type' => 'PLATFORM', 'issuer_carrier_id' => $policy->carrier_id, 'issuer_tenant_id' => $policy->tenant_id,
            'document_origin' => 'SYSTEM', 'document_stage' => 'POLICY', 'language' => 'BILINGUAL', 'policy_version' => (int) $policy->version,
            'security_level' => $category === self::CERTIFICATE ? 'PUBLIC_VERIFIABLE' : 'CUSTOMER_PRIVATE',
            'issued_at' => $certificate->issued_at ?? now(), 'generation_trigger' => 'POLICY_ISSUED',
            'valid_from' => $category === self::CERTIFICATE ? $policy->coverage_starts_at : null,
            'valid_until' => $category === self::CERTIFICATE ? $policy->coverage_ends_at : null,
            'provenance' => ['rendered_by' => 'OPESINSURE', 'certificate_serial' => $certificate->serial_number],
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        $number = app(\App\Application\Documents\Engine\DocumentNumberAllocator::class)->allocate($policy->tenant_id, $attributes['document_type_code']);

        return Document::create($attributes + ['status' => 'VALID', 'numbering_family' => $number['family'], 'document_number' => $number['number'], 'document_sequence' => $number['sequence'],
            'verification_code' => \App\Application\Documents\Engine\DocumentEngine::newVerificationCode()]);
    }

    private function disk(): string
    {
        return (string) config('lifecycle.documents_disk', 'local');
    }
}
