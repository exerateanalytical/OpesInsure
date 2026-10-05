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

    /**
     * Launch review: the discharge is issued from the published canonical template (DOC-179 CLAIM_DISCHARGE) through
     * the document engine, addressed to the payee in the payee's language, PENDING_SIGNATURE until signed. The former
     * platform rendering remains only as a fallback when the engine cannot issue (insurer not authorizing rendering,
     * template missing), so the settlement flow never stalls.
     */
    public function build(object $settlement, Claim $claim, User $actor): Document
    {
        $policy = $claim->policy()->with(['carrier.party', 'party'])->firstOrFail();
        $cur = (string) ($settlement->currency ?: 'XAF');
        $payee = (string) (\App\Models\Party::whereKey($settlement->payee_party_id)->value('display_name') ?? '');
        $money = fn ($minor) => \App\Application\Documents\Security\MappedFieldValues::money((int) $minor, $cur);
        $lines = array_map(fn (array $l) => sprintf('%s %s %s', $l['label'], $l['operator'], $money($l['amount_minor'])), json_decode((string) $settlement->breakdown, true)['lines'] ?? []);
        $engine = app(\App\Application\Documents\Engine\EventDocumentRouter::class);
        $item = $engine->issue('CLAIM_SETTLEMENT_DISCHARGE', 'CLAIM_DISCHARGE', [
            'tenant_id' => $claim->tenant_id, 'policy' => $policy, 'claim' => $claim, 'party_id' => $settlement->payee_party_id, 'currency' => $cur,
            'issuer' => 'INSURER', 'language' => \App\Application\Documents\Engine\EventDocumentRouter::partyLanguage($settlement->payee_party_id),
            'status' => 'PENDING_SIGNATURE', 'subject' => ['type' => 'CLAIM_SETTLEMENT', 'key' => (string) $settlement->id, 'label' => (string) $settlement->reference],
            'label' => 'Settlement '.$settlement->reference, 'provenance' => ['claim_settlement_id' => $settlement->id],
            'sources' => ['claim_settlement_id' => (string) $settlement->id],
            'fields' => array_filter([
                'settlement.payee' => $payee ?: null, 'settlement.amount' => (int) $settlement->amount_minor, 'settlement.reference' => $settlement->reference,
                'settlement.nature' => 'Full and final settlement / Règlement intégral et définitif',
                'claim.scope_of_discharge_release' => "Claim {$claim->claim_number} (policy {$policy->policy_number}) / Sinistre {$claim->claim_number} (police {$policy->policy_number})",
                'settlement.breakdown' => $lines ? implode(' · ', $lines) : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'sections' => [['heading' => 'Détail du règlement / Settlement breakdown', 'paragraphs' => $lines ?: ['—']]],
            'is_demo' => (bool) ($claim->is_demo ?? false),
        ]);
        if (($item['state'] ?? null) === 'GENERATED' && ($doc = Document::find($item['document_id']))) {
            return $doc;
        }

        return $this->legacy($settlement, $claim, $actor, $policy);
    }

    private function legacy(object $settlement, Claim $claim, User $actor, \App\Models\Policy $policy): Document
    {
        $type = $this->register->describe(self::TYPE);
        $number = $this->numbers->allocate($claim->tenant_id, self::TYPE);
        $verification = DocumentEngine::newVerificationCode();
        $carrierName = $policy->carrier?->party?->display_name ?? 'Insurer';
        $payee = (string) (\App\Models\Party::whereKey($settlement->payee_party_id)->value('display_name') ?? '');
        // R9: minor units (÷100) and XAF printed as FCFA, like every other issued document.
        $money = fn ($minor) => \App\Application\Documents\Security\MappedFieldValues::money((int) $minor, (string) ($settlement->currency ?: 'XAF'));
        $amount = $money($settlement->amount_minor);
        $lines = array_map(fn (array $l) => sprintf('%s %s %s', $l['label'], $l['operator'], $money($l['amount_minor'])), json_decode((string) $settlement->breakdown, true)['lines'] ?? []);

        $sections = [
            ['heading' => 'Quittance / Discharge receipt', 'paragraphs' => [
                "Je soussigné(e) {$payee} reconnais avoir accepté la somme de {$amount} en règlement définitif du sinistre {$claim->claim_number} (police {$policy->policy_number}) et donne quittance à {$carrierName}.",
                "I, {$payee}, accept the sum of {$amount} in full and final settlement of claim {$claim->claim_number} (policy {$policy->policy_number}) and discharge {$carrierName} from any further liability for this loss.",
            ]],
            ['heading' => 'Détail du règlement / Settlement breakdown', 'paragraphs' => $lines],
        ];
        $verifyUrl = rtrim((string) config('lifecycle.verify_url'), '/').'?code='.$verification;
        // D3: canonical secure shell.
        $bytes = app(\App\Application\Documents\Engine\SecureShellRenderer::class)->render([
            'type_code' => self::TYPE, 'number' => $number['number'], 'verification' => $verification, 'qr_url' => $verifyUrl,
            'verify_url' => (string) config('lifecycle.verify_url'), 'issuer_name' => $carrierName, 'policy' => $policy, 'claim' => $claim,
            'title_en' => 'Discharge receipt', 'title_fr' => 'Quittance', 'label' => 'Settlement '.$settlement->reference,
            'values' => ['party.name' => $policy->party?->display_name ?? '', 'policy.insurer' => $carrierName],
            'subject' => ['type' => 'CLAIM', 'key' => $claim->claim_number, 'label' => $claim->claim_number],
            'sections' => $sections, 'status' => 'PENDING_SIGNATURE', 'template_ref' => 'SYSTEM claim discharge',
        ]);

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
