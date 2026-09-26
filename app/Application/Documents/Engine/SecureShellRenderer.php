<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Application\Documents\Security\DocumentSecurityProfile;
use App\Models\Policy;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * D3 (DOCUMENT_SECURITY_COMPLETION_PLAN): the one entry point for PDFs rendered outside the pack engine
 * (certificates, policy schedule, quotation, payment receipt, account / sub-ledger statements, claim
 * discharge). Every one of them now renders in the canonical secure shell (pdf.engine-shell via
 * DocumentShellView): zones A..G, guilloche, microtext, watermark, seal, QR, hash, verification block,
 * letterhead and the demo overlay. Callers keep their own number, verification code and data; this class
 * only resolves the catalogue type + security profile and hands the view model to the shell.
 *
 * These paths do not produce a platform signature (only DocumentEngine::generate signs), so the signature
 * control is reported as not applied here rather than claimed.
 */
final class SecureShellRenderer
{
    public function __construct(private DocumentRegister $register, private DocumentSecurityProfile $security) {}

    /**
     * @param  array{type_code: string, number: string, verification?: ?string, verify_url?: ?string, qr_url?: ?string|false,
     *     issuer_name: string, letterhead?: ?array, policy?: ?Policy, currency?: ?string, values?: array, sections?: array,
     *     coverages?: array, label?: string, status?: string, lang?: string, issued_at?: mixed, template_ref?: string,
     *     title_en?: string, title_fr?: string, shell?: string, subject?: ?array, claim?: mixed, paper?: string, hash_basis?: mixed}  $s
     */
    public function render(array $s): string
    {
        $type = $this->register->describe($s['type_code']);
        $security = $this->security->resolve($type);
        if (! empty($s['shell']) && empty($security['master_shell_code'])) {
            $security['master_shell_code'] = $s['shell'];
        }
        if ($security['controls']['signature']['status'] === 'APPLIED') {
            $security['controls']['signature']['status'] = 'NOT_APPLICABLE';
        }
        $verification = (string) ($s['verification'] ?? '');
        $verifyBase = rtrim((string) ($s['verify_url'] ?? config('document_security.verification.url', config('lifecycle.verify_url'))), '/');
        $qrUrl = $s['qr_url'] ?? ($verification !== '' ? $verifyBase.'?code='.$verification : false);
        $qr = $qrUrl ? (new QRCode(new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'outputBase64' => true, 'eccLevel' => EccLevel::M, 'addQuietzone' => true])))->render($qrUrl) : null;
        $policy = $s['policy'] ?? null;
        $policyView = $policy ?? (object) ['policy_number' => null, 'version' => 0, 'currency' => $s['currency'] ?? 'XAF', 'proposal' => null];
        $issuedAt = \Illuminate\Support\Carbon::parse($s['issued_at'] ?? now());
        $values = (array) ($s['values'] ?? []);
        $sections = (array) ($s['sections'] ?? []);
        $templateRef = (string) ($s['template_ref'] ?? 'SYSTEM '.$s['type_code']);
        $contentHash = hash('sha256', json_encode([$s['number'], $verification, $values, $sections, $s['hash_basis'] ?? null], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        $data = DocumentShellView::data([
            'lang' => $s['lang'] ?? 'BILINGUAL',
            'template' => (object) ['code' => $templateRef, 'version' => 1, 'title_fr' => $s['title_fr'] ?? ($type['name_fr'] ?? $type['name_en']), 'title_en' => $s['title_en'] ?? $type['name_en'], 'ownership' => 'SYSTEM'],
            'type' => $type, 'security' => $security, 'values' => $values, 'requirements' => [],
            'number' => $s['number'], 'issuerName' => $s['issuer_name'], 'carrierName' => $s['issuer_name'], 'intermediary' => null,
            'policy' => $policyView, 'product' => null, 'subject' => $s['subject'] ?? null, 'subjectFacts' => [], 'label' => $s['label'] ?? $type['name_en'],
            'issuedAt' => $issuedAt, 'verification' => $verification !== '' ? $verification : '—', 'qr' => $qr, 'verifyUrl' => $verifyBase,
            'sections' => $sections, 'contentHash' => $contentHash, 'profile' => null, 'claim' => $s['claim'] ?? null, 'transaction' => null,
            'coverages' => (array) ($s['coverages'] ?? []), 'status' => $s['status'] ?? 'ISSUED', 'letterhead' => $s['letterhead'] ?? null,
        ]);

        return Pdf::loadView('pdf.engine-shell', $data)->setPaper($s['paper'] ?? 'a4')->output();
    }
}
