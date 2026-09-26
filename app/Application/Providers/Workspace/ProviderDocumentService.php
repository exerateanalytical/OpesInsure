<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace;

use App\Application\Audit\AuditWriter;
use App\Application\Documents\Engine\DocumentAccessPolicy;
use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Providers\Portal\ProviderScope;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Document;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * D4 — provider documents issued by the document engine from the provider portal flows
 * (canonical spec ids → catalogue types):
 *  DOC-064 Eligibility Confirmation           ELIGIBILITY_CONFIRMATION         on a point-of-care eligibility check
 *  DOC-065 Preauthorization Request           PREAUTHORIZATION_REQUEST         on a preauthorization submission
 *  DOC-066..071 approval / partial / rejection / guarantee of payment / admission / stay extension:
 *          already issued by PreauthorizationService through DocumentEngine::fire (PREAUTH_* triggers)
 *  DOC-072 Explanation of Benefits            EXPLANATION_OF_BENEFITS          on provider claim adjudication
 *  DOC-198 Provider Settlement Statement      PROVIDER_SETTLEMENT_STATEMENT    when a settlement batch is paid (remittance)
 *  DOC-215 Provider Contract                  PROVIDER_CONTRACT                when a contract is activated
 *  DOC-216 Provider Tariff Schedule           PROVIDER_TARIFF_SCHEDULE         when a tariff version is approved
 *
 * Issuance never breaks the business flow (savepoint + report). Issuance gates are the engine's:
 * the insurer's issuance profile must authorize OpesInsure rendering, a published template must exist,
 * required fields must be present — otherwise the item carries AWAITING_CARRIER_DOCUMENT /
 * TEMPLATE_MISSING / BLOCKED_* and nothing is numbered. Medical content is limited to what the provider
 * needs (no clinical notes, no diagnosis history).
 */
final class ProviderDocumentService
{
    /** Types a provider sees in its portal (own documents only); medical ones only for clinical roles. */
    public const PROVIDER_TYPES = [
        'ELIGIBILITY_CONFIRMATION', 'PREAUTHORIZATION_REQUEST', 'PREAUTHORIZATION_APPROVAL', 'PARTIAL_PREAUTHORIZATION_APPROVAL', 'PREAUTHORIZATION_REJECTION',
        'GUARANTEE_OF_PAYMENT', 'HOSPITAL_ADMISSION_AUTHORIZATION', 'HOSPITAL_STAY_EXTENSION_AUTHORIZATION', 'EXPLANATION_OF_BENEFITS',
        'PROVIDER_SETTLEMENT_STATEMENT', 'PROVIDER_CONTRACT', 'PROVIDER_TARIFF_SCHEDULE',
    ];

    public function __construct(private readonly DocumentEngine $engine, private readonly ProviderAccess $access, private readonly AuditWriter $audit) {}

    // ----------------------------------------------------------------- triggers

    /** DOC-064: a check that located a policy (whatever the outcome) is confirmed in writing. */
    public function onEligibilityChecked(string $tenantId, string $checkId, ?User $actor): ?array
    {
        $c = DB::table('health_eligibility_checks')->where(['tenant_id' => $tenantId, 'id' => $checkId])->first();
        if (! $c || ! $c->policy_id || ! $c->provider_profile_id) {
            return null;
        }
        $policy = Policy::find($c->policy_id);
        $member = $c->health_member_id ? DB::table('health_members')->where('id', $c->health_member_id)->first() : null;
        $memberRef = $member->member_number ?? $member->id ?? $c->member_ref_hash;
        $reasons = array_column((array) json_decode((string) $c->reasons, true), 'code');

        return $this->issue('ELIGIBILITY_CHECKED', $tenantId, $c->provider_profile_id, $policy, $policy?->carrier_id, $policy?->currency,
            ['type' => 'ELIGIBILITY', 'key' => 'eligibility:'.$c->id, 'label' => (string) $memberRef], (string) $memberRef, [
                ['heading' => 'Eligibility result / Résultat d\'éligibilité', 'paragraphs' => array_filter([
                    'Outcome / Résultat: '.$c->outcome, 'Checked at / Vérifié le: '.$c->checked_at.' ('.config('app.timezone').')',
                    'Service: '.($c->service_code ?? '-').' — '.$c->service_date, $c->benefit_code ? 'Benefit / Garantie: '.$c->benefit_code : null,
                    $reasons ? 'Reasons / Motifs: '.implode(', ', $reasons) : null, 'Verification reference / Référence: '.$c->id,
                ])],
            ], ['eligibility_check_id' => $c->id], $actor);
    }

    /** DOC-065: the provider's submitted request, as received (no clinical notes). */
    public function onPreauthSubmitted(string $tenantId, string $preauthId, ?User $actor): ?array
    {
        $pa = DB::table('health_preauthorizations')->where(['tenant_id' => $tenantId, 'id' => $preauthId])->first();
        if (! $pa) {
            return null;
        }
        $lines = DB::table('health_preauthorization_lines')->where('health_preauthorization_id', $pa->id)->whereNull('extension_id')->orderBy('line_no')->get()
            ->map(fn ($l) => sprintf('%d. %s × %d — %s', $l->line_no, $l->service_code ?? $l->provider_code ?? '-', $l->quantity, self::money($l->requested_amount_minor, $pa->currency)))->all();

        return $this->issue('PREAUTH_SUBMITTED', $tenantId, $pa->provider_profile_id, Policy::find($pa->policy_id), $pa->carrier_id, $pa->currency,
            ['type' => 'PREAUTHORIZATION', 'key' => 'preauth-request:'.$pa->id, 'label' => $pa->preauth_number], (string) $pa->member_ref, [
                ['heading' => 'Request / Demande', 'paragraphs' => ['Reference / Référence: '.$pa->preauth_number, 'Type: '.$pa->request_type, 'Service date / Date de soins: '.$pa->service_date,
                    'Requested / Demandé: '.self::money($pa->requested_amount_minor, $pa->currency), 'Insurer share requested / Part assureur demandée: '.self::money($pa->insurer_amount_minor, $pa->currency)]],
                ['heading' => 'Lines / Lignes', 'paragraphs' => $lines],
            ], ['health_preauthorization_id' => $pa->id], $actor);
    }

    /** DOC-072: explanation of benefits of an adjudicated provider claim (line decisions + reason codes). */
    public function onClaimAdjudicated(string $tenantId, string $providerClaimId, ?User $actor): ?array
    {
        $c = DB::table('health_provider_claims')->where(['tenant_id' => $tenantId, 'id' => $providerClaimId])->first();
        if (! $c || ! in_array($c->status, ['APPROVED', 'PARTIALLY_APPROVED', 'REJECTED'], true)) {
            return null;
        }
        $policy = $c->policy_id ? Policy::find($c->policy_id) : null;
        $lines = DB::table('health_provider_claim_lines')->where('health_provider_claim_id', $c->id)->orderBy('line_no')->get()
            ->map(fn ($l) => sprintf('%d. %s × %d — billed %s, allowed %s, insurer %s, member %s, rejected %s — %s%s', $l->line_no, $l->provider_code ?? '-', $l->quantity,
                self::money($l->billed_minor, $c->currency), self::money($l->allowed_minor, $c->currency), self::money($l->insurer_share_minor, $c->currency),
                self::money($l->member_share_minor, $c->currency), self::money($l->rejected_minor, $c->currency), $l->decision ?? '-', $l->reason_code ? ' ('.$l->reason_code.')' : ''))->all();

        return $this->issue('PROVIDER_CLAIM_ADJUDICATED', $tenantId, $c->provider_profile_id, $policy, $policy?->carrier_id ?? $this->contractCarrier($c->provider_contract_id), $c->currency,
            ['type' => 'PROVIDER_CLAIM', 'key' => 'provider-claim:'.$c->id, 'label' => $c->claim_number], (string) ($c->member_reference ?? $c->member_party_id ?? ''), [
                ['heading' => 'Decision / Décision', 'paragraphs' => ['Claim / Demande: '.$c->claim_number.' — invoice / facture '.$c->invoice_reference, 'Decision / Décision: '.$c->status,
                    'Billed / Facturé: '.self::money($c->billed_minor, $c->currency), 'Allowed / Admis: '.self::money($c->allowed_minor, $c->currency),
                    'Copay / Ticket modérateur: '.self::money($c->copay_minor, $c->currency), 'Insurer pays / Part assureur: '.self::money($c->insurer_share_minor, $c->currency),
                    'Member pays / Part adhérent: '.self::money($c->member_share_minor, $c->currency), 'Rejected / Rejeté: '.self::money($c->rejected_minor, $c->currency)]],
                ['heading' => 'Lines / Lignes', 'paragraphs' => $lines],
            ], ['health_provider_claim_id' => $c->id], $actor, $c->claim_id);
    }

    /** DOC-198: settlement statement / remittance advice of a paid batch. */
    public function onSettlementPaid(string $tenantId, string $batchId, ?User $actor): ?array
    {
        $b = DB::table('health_provider_settlement_batches')->where(['tenant_id' => $tenantId, 'id' => $batchId])->first();
        if (! $b || $b->status !== 'PAID') {
            return null;
        }
        $claims = DB::table('health_provider_claims')->where('settlement_batch_id', $b->id)->orderBy('claim_number')->get();
        $lines = $claims->map(fn ($c) => sprintf('%s — invoice %s — %s', $c->claim_number, $c->invoice_reference, self::money($c->insurer_share_minor, $b->currency)))->all();

        return $this->issue('PROVIDER_SETTLEMENT_PAID', $tenantId, $b->provider_profile_id, null, $this->contractCarrier($claims->first()?->provider_contract_id), $b->currency,
            ['type' => 'SETTLEMENT', 'key' => 'provider-settlement:'.$b->id, 'label' => $b->batch_number], null, [
                ['heading' => 'Settlement / Règlement', 'paragraphs' => ['Batch / Lot: '.$b->batch_number, 'Claims / Demandes: '.$b->claim_count, 'Total paid / Total réglé: '.self::money($b->total_minor, $b->currency),
                    'Payment reference / Référence de paiement: '.($b->payment_reference ?? '-'), 'Paid at / Payé le: '.($b->paid_at ?? '-')]],
                ['heading' => 'Remittance detail / Détail du règlement', 'paragraphs' => $lines],
            ], ['health_provider_settlement_batch_id' => $b->id], $actor);
    }

    /** DOC-215: provider contract (convention) on activation. */
    public function onContractActivated(string $tenantId, string $contractId, ?User $actor): ?array
    {
        $k = DB::table('provider_contracts as k')->join('provider_networks as n', 'n.id', '=', 'k.provider_network_id')->where(['k.tenant_id' => $tenantId, 'k.id' => $contractId])
            ->select('k.*', 'n.name as network_name', 'n.carrier_id')->first();
        if (! $k || $k->status !== 'ACTIVE') {
            return null;
        }

        return $this->issue('PROVIDER_CONTRACT_ACTIVATED', $tenantId, $k->provider_profile_id, null, $k->carrier_id, null,
            ['type' => 'CONTRACT', 'key' => 'provider-contract:'.$k->id, 'label' => $k->contract_number], null, [
                ['heading' => 'Convention / Contract', 'paragraphs' => array_filter(['Contract / Convention: '.$k->contract_number, 'Network / Réseau: '.$k->network_name,
                    'Effective / En vigueur: '.$k->effective_from.' → '.($k->effective_to ?? 'open / indéterminée'), 'Settlement mode / Mode de règlement: '.$k->settlement_mode,
                    $k->document_reference ? 'Signed contract reference / Référence du contrat signé: '.$k->document_reference : null])],
            ], ['provider_contract_id' => $k->id], $actor, null, ['valid_from' => $k->effective_from, 'valid_until' => $k->effective_to]);
    }

    /** DOC-216: tariff schedule of an approved tariff version. */
    public function onTariffApproved(string $tenantId, string $tariffVersionId, ?User $actor): ?array
    {
        $t = DB::table('provider_tariff_versions as v')->join('provider_contracts as k', 'k.id', '=', 'v.provider_contract_id')->join('provider_networks as n', 'n.id', '=', 'k.provider_network_id')
            ->where('k.tenant_id', $tenantId)->where('v.id', $tariffVersionId)->select('v.*', 'k.contract_number', 'k.provider_profile_id', 'n.carrier_id')->first();
        if (! $t || $t->status !== 'APPROVED') {
            return null;
        }
        $lines = DB::table('provider_tariff_lines as l')->join('medical_services as s', 's.id', '=', 'l.medical_service_id')->where('l.provider_tariff_version_id', $t->id)->orderBy('s.code')
            ->get(['s.code', 's.name', 'l.contracted_price_minor', 'l.price_minor', 'l.copay_minor', 'l.insurer_share_percent', 'l.preauthorization_required'])
            ->map(fn ($l) => sprintf('%s %s — %s (copay %s, insurer %s%%)%s', $l->code, $l->name, self::money($l->contracted_price_minor ?? $l->price_minor, $t->currency),
                self::money($l->copay_minor, $t->currency), $l->insurer_share_percent ?? '-', $l->preauthorization_required ? ' — preauthorization required / accord préalable' : ''))->all();

        return $this->issue('PROVIDER_TARIFF_APPROVED', $tenantId, $t->provider_profile_id, null, $t->carrier_id, $t->currency,
            ['type' => 'TARIFF', 'key' => 'provider-tariff:'.$t->id, 'label' => $t->contract_number.' v'.$t->version], null, [
                ['heading' => 'Tariff / Grille', 'paragraphs' => ['Contract / Convention: '.$t->contract_number, 'Version: '.$t->version, 'Effective / En vigueur: '.$t->effective_from.' → '.($t->effective_to ?? 'open'), 'Currency / Devise: '.$t->currency]],
                ['heading' => 'Services / Actes', 'paragraphs' => $lines],
            ], ['provider_tariff_version_id' => $t->id, 'provider_contract_id' => $t->provider_contract_id], $actor, null, ['valid_from' => $t->effective_from, 'valid_until' => $t->effective_to]);
    }

    // ----------------------------------------------------------------- provider & insurer access

    /** The provider's own engine documents. Medical documents only for clinical roles. */
    public function listForProvider(User $user, ProviderScope $s, array $f = []): array
    {
        $clinical = $this->access->mayReadClinical($user, $s);

        return Document::where('provider_profile_id', $s->providerId)->whereIn('document_type_code', self::PROVIDER_TYPES)
            ->when(! $clinical, fn ($q) => $q->where('security_level', '!=', 'MEDICAL_RESTRICTED'))
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('document_type_code', $t))
            ->orderByDesc('created_at')->limit(500)
            ->get(['id', 'document_type_code', 'document_number', 'title', 'status', 'security_level', 'security_tier', 'subject_label', 'issued_at', 'valid_from', 'valid_until'])
            ->map(fn (Document $d) => $d->toArray() + ['status_effective' => DocumentEngine::effectiveStatus($d)])->all();
    }

    public function downloadForProvider(User $user, ProviderScope $s, string $id): StreamedResponse
    {
        $d = $this->providerDocument($s, $id);
        if ($d->security_level === 'MEDICAL_RESTRICTED' && ! $this->access->mayReadClinical($user, $s)) {
            throw new ApiProblemException('CLINICAL_ACCESS_REQUIRED', 403, 'Medical documents are restricted to clinical roles.');
        }
        $this->audit->record('document.provider.downloaded', 'document', $d->id, ['provider_id' => $s->providerId, 'type' => $d->document_type_code, 'number' => $d->document_number]);

        return $this->stream($d);
    }

    /** Insurer staff (tenant): documents.read + the level permission (DocumentAccessPolicy). */
    public function downloadForInsurer(User $user, string $tenantId, string $id): StreamedResponse
    {
        $d = Document::where('tenant_id', $tenantId)->whereNotNull('provider_profile_id')->whereKey($id)->first()
            ?? throw new ApiProblemException('DOCUMENT_NOT_FOUND', 404, 'Document not found.');
        if (! DocumentAccessPolicy::staffMay($user, $d)) {
            throw new ApiProblemException('DOCUMENT_ACCESS_DENIED', 403, 'This document needs the permission of its security level.');
        }
        $this->audit->record('document.insurer.downloaded', 'document', $d->id, ['type' => $d->document_type_code, 'number' => $d->document_number]);

        return $this->stream($d);
    }

    /** @return array<int, array<string, mixed>> */
    public function listForInsurer(User $user, string $tenantId, array $f = []): array
    {
        return Document::where('tenant_id', $tenantId)->whereNotNull('provider_profile_id')
            ->when($f['provider_id'] ?? null, fn ($q, $p) => $q->where('provider_profile_id', $p))
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('document_type_code', $t))
            ->orderByDesc('created_at')->limit(500)->get()
            ->filter(fn (Document $d) => DocumentAccessPolicy::staffMay($user, $d))
            ->map(fn (Document $d) => $d->only(['id', 'provider_profile_id', 'document_type_code', 'document_number', 'title', 'status', 'security_level', 'security_tier', 'subject_label', 'issued_at']))
            ->values()->all();
    }

    // ----------------------------------------------------------------- internals

    private function providerDocument(ProviderScope $s, string $id): Document
    {
        $d = Document::whereKey($id)->first();
        $own = $d && ($d->provider_profile_id === $s->providerId || $this->viaOwnPreauth($s, $d));
        if (! $own) {
            throw new ApiProblemException('DOCUMENT_NOT_FOUND', 404, 'Document not found.');
        }

        return $d;
    }

    /** Pack documents of the provider's own preauthorizations (GOP pack, issued via fire()). */
    private function viaOwnPreauth(ProviderScope $s, Document $d): bool
    {
        return $d->pack_manifest_id !== null && in_array($d->document_type_code, self::PROVIDER_TYPES, true)
            && DB::table('health_preauthorizations')->where('provider_profile_id', $s->providerId)->where('gop_manifest_id', $d->pack_manifest_id)->exists();
    }

    private function stream(Document $d): StreamedResponse
    {
        $disk = Storage::disk((string) config('lifecycle.documents_disk', 'local'));
        if (! $disk->exists($d->storage_key)) {
            throw new ApiProblemException('DOCUMENT_FILE_MISSING', 404, 'The document file is not available.');
        }

        return $disk->download($d->storage_key, ($d->document_number ?? $d->id).'.pdf', ['Content-Type' => 'application/pdf', 'X-Document-Sha256' => (string) $d->sha256]);
    }

    private function contractCarrier(?string $contractId): ?string
    {
        return $contractId ? DB::table('provider_contracts as k')->join('provider_networks as n', 'n.id', '=', 'k.provider_network_id')->where('k.id', $contractId)->value('n.carrier_id') : null;
    }

    /**
     * @param  array<int, array{heading: string, paragraphs: array<int, string>}>  $sections
     * @param  array<string, mixed>  $sources
     * @param  array<string, mixed>  $extra
     */
    private function issue(string $trigger, string $tenantId, string $providerId, ?Policy $policy, ?string $carrierId, ?string $currency, array $subject, ?string $memberRef,
        array $sections, array $sources, ?User $actor, ?string $claimId = null, array $extra = []): ?array
    {
        try {
            $providerName = DB::table('provider_profiles as p')->leftJoin('parties', 'parties.id', '=', 'p.party_id')->where('p.id', $providerId)
                ->selectRaw('COALESCE(parties.display_name, p.official_name, p.trade_name) as n')->value('n');
            $ctx = ['tenant_id' => $tenantId, 'carrier_id' => $carrierId, 'currency' => $currency ?? $policy?->currency ?? 'XAF', 'policy' => $policy, 'provider_id' => $providerId,
                'subject' => $subject, 'sections' => $sections, 'sources' => $sources,
                'fields' => array_filter(['provider.name' => $providerName, 'member.reference' => $memberRef ?: null], fn ($v) => $v !== null && $v !== '')] + $extra;
            if ($claimId) {
                $ctx['claim'] = \App\Models\Claim::find($claimId);
            }

            return DB::transaction(fn () => $this->engine->issueProviderDocument($trigger, $ctx, $actor));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private static function money($minor, ?string $currency): string
    {
        return $minor === null ? '-' : number_format(((int) $minor) / 100, 0, '.', ' ').' '.($currency ?? '');
    }
}
