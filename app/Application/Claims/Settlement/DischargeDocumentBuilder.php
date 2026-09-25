<?php

declare(strict_types=1);

namespace App\Application\Claims\Settlement;

use App\Application\Audit\AuditWriter;
use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Engine\DocumentNumberAllocator;
use App\Application\Documents\Engine\DocumentRegister;
use App\Models\Claim;
use App\Models\Document;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Discharge receipt / quittance for an accepted settlement. Rendered with the document engine's
 * numbering, register (type DISCHARGE, SETTLEMENT stage) and PDF layout, stored as a PENDING_SIGNATURE
 * engine document linked to the claim, then sent for e-signature through SignatureService.
 */
final class DischargeDocumentBuilder
{
    public const TYPE = 'DISCHARGE';

    public function __construct(private DocumentNumberAllocator $numbers, private DocumentRegister $register, private AuditWriter $audit) {}

    public function build(object $settlement, Claim $claim, User $actor): Document
    {
        $policy = $claim->policy()->with(['carrier.party', 'party'])->firstOrFail();
        $type = $this->register->describe(self::TYPE);
        $number = $this->numbers->allocate($claim->tenant_id, self::TYPE);
        $verification = DocumentEngine::newVerificationCode();
        $carrierName = $policy->carrier?->party?->display_name ?? 'Insurer';
        $payee = (string) (\App\Models\Party::whereKey($settlement->payee_party_id)->value('display_name') ?? '');
        $amount = number_format((int) $settlement->amount_minor, 0, ',', ' ').' '.$settlement->currency;
        $lines = array_map(fn (array $l) => sprintf('%s %s %s', $l['label'], $l['operator'], number_format((int) $l['amount_minor'], 0, ',', ' ')), json_decode((string) $settlement->breakdown, true)['lines'] ?? []);

        $sections = [
            ['heading' => 'Quittance / Discharge receipt', 'paragraphs' => [
                "Je soussigné(e) {$payee} reconnais avoir accepté la somme de {$amount} en règlement définitif du sinistre {$claim->claim_number} (police {$policy->policy_number}) et donne quittance à {$carrierName}.",
                "I, {$payee}, accept the sum of {$amount} in full and final settlement of claim {$claim->claim_number} (policy {$policy->policy_number}) and discharge {$carrierName} from any further liability for this loss.",
            ]],
            ['heading' => 'Détail du règlement / Settlement breakdown', 'paragraphs' => $lines],
        ];
        $verifyUrl = rtrim((string) config('lifecycle.verify_url'), '/').'?code='.$verification;
        $bytes = Pdf::loadView('pdf.engine-document', [
            'lang' => 'BILINGUAL', 'titleEn' => 'Discharge receipt', 'titleFr' => 'Quittance', 'documentNumber' => $number['number'],
            'issuerName' => $carrierName, 'intermediary' => null, 'carrierName' => $carrierName, 'policyNumber' => $policy->policy_number, 'policyVersion' => (int) $policy->version,
            'insuredName' => $policy->party?->display_name ?? '', 'productName' => null, 'subjectLabel' => $claim->claim_number,
            'validFrom' => null, 'validUntil' => null, 'eventLabel' => 'Settlement '.$settlement->reference, 'issuedAt' => now()->format('d/m/Y H:i'),
            'verificationCode' => $verification, 'qr' => null, 'verifyUrl' => $verifyUrl, 'sections' => $sections, 'coverages' => [], 'signatory' => null,
            'templateRef' => 'SYSTEM claim discharge',
        ])->setPaper('a4')->output();

        $key = 'documents/'.$claim->tenant_id.'/claims/'.$claim->id.'/'.$number['number'].'.pdf';
        Storage::disk((string) config('lifecycle.documents_disk', 'local'))->put($key, $bytes);

        $doc = Document::create([
            'tenant_id' => $claim->tenant_id, 'party_id' => $settlement->payee_party_id, 'policy_id' => $policy->id, 'claim_id' => $claim->id,
            'category' => 'ENGINE_'.self::TYPE, 'storage_key' => $key, 'mime_type' => 'application/pdf', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'scan_status' => 'CLEAN', 'verification_status' => 'VERIFIED', 'ocr_data' => [],
            'document_type_code' => self::TYPE, 'document_type_id' => $type['id'] ?? null, 'document_group' => $type['group_code'] ?? null,
            'policy_version' => (int) $policy->version, 'subject_type' => 'CLAIM_SETTLEMENT', 'subject_key' => $settlement->id, 'subject_label' => $settlement->reference,
            'title' => 'Discharge receipt', 'issuer_type' => 'INSURER', 'issuer_carrier_id' => $policy->carrier_id, 'issuer_tenant_id' => $claim->tenant_id,
            'language' => 'BILINGUAL', 'document_origin' => 'SYSTEM', 'document_stage' => 'SETTLEMENT', 'security_level' => $type['security_level'] ?? 'CUSTOMER_PRIVATE',
            'status' => 'PENDING_SIGNATURE', 'numbering_family' => $number['family'], 'document_number' => $number['number'], 'document_sequence' => $number['sequence'],
            'verification_code' => $verification, 'generation_trigger' => 'CLAIM_SETTLEMENT_DISCHARGE', 'issued_at' => now(),
            'provenance' => ['rendered_by' => 'OPESINSURE', 'on_behalf_of' => 'INSURER', 'event' => 'Settlement '.$settlement->reference, 'claim_settlement_id' => $settlement->id],
            'uploaded_by' => $actor->id,
        ]);
        $this->audit->record('document.generated', 'document', $doc->id, ['number' => $doc->document_number, 'type' => self::TYPE, 'claim_settlement_id' => $settlement->id]);

        return $doc;
    }
}
