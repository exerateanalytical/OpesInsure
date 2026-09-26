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

    /** Secure-shell spec of the certificate / schedule (values from the policy and its frozen terms; nothing recomputed). */
    public static function shellSpec(Policy $policy, PolicyCertificate $certificate, string $category, ?string $token, string $verifyUrl, ?array $letterhead): array
    {
        $policy->loadMissing(['carrier.party', 'party', 'proposal.offer.product', 'proposal.offer.quote']);
        $product = $policy->proposal?->offer?->product;
        $carrierName = $policy->carrier?->party?->display_name ?? 'Insurer';
        $terms = $policy->terms_snapshot ?? [];
        $riskFacts = (array) ($policy->proposal?->offer?->quote?->risk_facts ?? []);
        $isCert = $category === self::CERTIFICATE;
        $cur = $terms['currency'] ?? $policy->currency;
        $m = fn ($v) => number_format(((int) $v) / 100, 0, '.', ' ').' '.$cur;
        $risk = [];
        foreach ($riskFacts as $k => $v) {
            if (is_scalar($v)) {
                $risk[] = ucwords(str_replace('_', ' ', (string) $k)).': '.(is_bool($v) ? ($v ? 'Yes' : 'No') : $v);
            }
        }
        $sections = [['heading' => 'Certificat / Certificate', 'paragraphs' => array_values(array_filter([
            'N° de série / Certificate serial: '.$certificate->serial_number,
            'Branche / Class of insurance: '.($product?->line_code ?? $policy->proposal?->offer?->quote?->line_code ?? '—'),
            'Émis le / Issued: '.(optional($isCert ? $certificate->issued_at : $policy->issued_at)->format('d/m/Y H:i') ?? '—'),
        ]))]];
        if (! $isCert) {
            $sections[] = ['heading' => 'Prime / Premium', 'paragraphs' => [
                'Prime / Premium: '.$m($terms['premium_minor'] ?? 0), 'Taxes: '.$m($terms['tax_minor'] ?? 0), 'Frais / Fees: '.$m($terms['fee_minor'] ?? 0),
                'Total payé / Total paid: '.$m($terms['total_minor'] ?? $policy->premium_minor),
            ]];
        }
        if ($risk !== []) {
            $sections[] = ['heading' => 'Risque assuré / Insured risk', 'paragraphs' => $risk];
        }
        $coverages = array_map(fn ($c) => is_array($c) ? ['name' => is_array($c['name'] ?? null) ? ($c['name']['en'] ?? reset($c['name'])) : ($c['name'] ?? $c['code'] ?? '')] + $c : $c,
            (array) ($terms['coverage_snapshot']['coverages'] ?? []));

        return [
            'type_code' => $isCert ? 'PROOF_OF_COVER' : 'POLICY_SCHEDULE', 'shell' => $isCert ? 'TPL-SHELL-POLICY-CERTIFICATE-001' : 'TPL-SHELL-POLICY-SCHEDULE-001',
            'number' => (string) $certificate->serial_number, 'verification' => $token, 'qr_url' => $verifyUrl,
            'verify_url' => (string) config('lifecycle.verify_url'), 'issuer_name' => $carrierName, 'letterhead' => $letterhead, 'policy' => $policy,
            'values' => array_filter([
                'party.name' => $policy->party?->display_name ?? 'Policyholder', 'policy.insurer' => $carrierName, 'policy.product' => $product?->name ?? 'Insurance cover',
                'policy.effective_from' => $policy->coverage_starts_at?->toIso8601String(), 'policy.effective_until' => $policy->coverage_ends_at?->toIso8601String(),
                'risk.registration_number' => $riskFacts['registration_number'] ?? null,
            ], fn ($v) => $v !== null),
            'sections' => $sections, 'coverages' => $isCert ? [] : $coverages, 'label' => $isCert ? 'CERTIFICATE '.$certificate->serial_number : 'POLICY SCHEDULE',
            'status' => $isCert ? 'VALID' : 'ISSUED', 'issued_at' => $certificate->issued_at ?? now(),
            'template_ref' => $isCert ? 'SYSTEM policy certificate' : 'SYSTEM policy schedule',
        ];
    }

    private function render(Policy $policy, PolicyCertificate $certificate, string $category, ?string $token, ?Document $existing): Document
    {
        $token ??= self::tokenOf($certificate);
        $verifyUrl = self::verifyUrl($certificate);
        // D3: rendered in the canonical secure shell; serial, verification token and policy data unchanged.
        $letterhead = \App\Application\Documents\Letterhead\LetterheadResolver::forPolicy($policy);
        $bytes = app(\App\Application\Documents\Engine\SecureShellRenderer::class)->render(self::shellSpec($policy, $certificate, $category, $token, $verifyUrl, $letterhead));
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
            'provenance' => ['rendered_by' => 'OPESINSURE', 'certificate_serial' => $certificate->serial_number, 'letterhead' => $letterhead['snapshot']],
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
