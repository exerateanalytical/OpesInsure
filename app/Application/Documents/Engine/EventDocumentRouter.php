<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Application\Documents\Security\MappedFieldValues;
use App\Models\Carrier;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Launch review gap 3: business events that have a published canonical template but issued no document. Fed by
 * OutboxWriter::record() and AuditWriter::record() (like LaunchNotificationRouter), so no producer has to remember to
 * issue; an event recorded on both paths issues once (DocumentEngine::issueEventDocument is idempotent per
 * type / subject / trigger). Each document goes through the engine (published template, required fields, number,
 * verification code, master shell) in its recipient's language (EN / FR, BILINGUAL when unknown).
 *
 *  quote.generated                    → INSURANCE_QUOTE (DOC-001)                    QUOTE_GENERATED
 *  proposal.submitted                 → INSURANCE_PROPOSAL (DOC-003, submitted copy) PROPOSAL_SUBMITTED
 *  payment.status.changed SUCCEEDED   → PAYMENT_RECEIPT (DOC-191)                    PAYMENT_RECONCILED
 *  refund.approved                    → REFUND_ADVICE (DOC-192)                      REFUND_APPROVED
 *  bordereau.approved                 → PREMIUM / CLAIMS / RISK_BORDEREAU (DOC-206..208)  BORDEREAU_APPROVED
 *  kyc_submission.information_requested → KYC_REQUEST (DOC-217)                     KYC_INFORMATION_REQUESTED
 *  kyc_submission.approved|rejected|remediation_requested → KYC_APPROVAL_REMEDIATION_NOTICE (DOC-218)  KYC_DECIDED
 *  renewal.due → RENEWAL_NOTICE (DOC-151) · renewal.quoted → RENEWAL_QUOTE (DOC-152)
 *  renewal.declined → NON_RENEWAL_NOTICE (DOC-154) · renewal.lapsed → POLICY_EXPIRY_NOTICE (DOC-159)
 *
 * Complaint letters: the canonical register (DOC-001..220) has no complaint letter template, so none is issued.
 * Never throws into the producer, never re-enters itself.
 */
final class EventDocumentRouter
{
    private static bool $routing = false;

    private const EVENTS = [
        'quote.generated' => 'quote', 'proposal.submitted' => 'proposal', 'payment.status.changed' => 'payment', 'refund.approved' => 'refund',
        'bordereau.approved' => 'bordereau', 'kyc_submission.information_requested' => 'kyc', 'kyc_submission.approved' => 'kyc',
        'kyc_submission.rejected' => 'kyc', 'kyc_submission.remediation_requested' => 'kyc',
        'renewal.due' => 'renewal', 'renewal.quoted' => 'renewal', 'renewal.declined' => 'renewal', 'renewal.lapsed' => 'renewal',
    ];

    public function __construct(private DocumentEngine $engine) {}

    public static function observe(string $event, ?string $subjectType, ?string $subjectId, array $data = []): void
    {
        if (self::$routing || ! isset(self::EVENTS[$event]) || ! $subjectId) {
            return;
        }
        self::$routing = true;
        try {
            $method = self::EVENTS[$event];
            // No published canonical template at all (fresh install): nothing to issue.
            if (Schema::hasTable('document_templates') && DB::table('document_templates')->where('status', 'PUBLISHED')->exists()) {
                // Savepoint: a failure here never aborts the producer's transaction.
                DB::transaction(fn () => app(self::class)->{$method}($event, $subjectId, $data));
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            self::$routing = false;
        }
    }

    /** Recipient language: the party's own app locale (EN / FR), else BILINGUAL. */
    public static function partyLanguage(?string $partyId): string
    {
        $l = $partyId ? strtoupper((string) User::where('party_id', $partyId)->orderBy('created_at')->value('locale')) : '';

        return in_array($l, ['EN', 'FR'], true) ? $l : 'BILINGUAL';
    }

    public static function userLanguage(?string $userId): string
    {
        $l = $userId ? strtoupper((string) User::whereKey($userId)->value('locale')) : '';

        return in_array($l, ['EN', 'FR'], true) ? $l : 'BILINGUAL';
    }

    /** The organisation that produced the record: a broker tenant issues as BROKER, otherwise the platform. */
    public static function tenantIssuer(?string $tenantId): string
    {
        return $tenantId && Tenant::whereKey($tenantId)->value('type') === 'BROKER' ? 'BROKER' : 'PLATFORM';
    }

    /** Insurer documents on the insurer's letterhead when it authorizes OpesInsure rendering; otherwise the producing tenant. */
    public function insurerOr(Policy $policy): string
    {
        return DocumentEngine::mayRenderForInsurer($this->engine->profileFor($policy)) ? 'INSURER' : self::tenantIssuer($policy->tenant_id);
    }

    /** Issue + return the first item (shared with on-demand callers). @return array<string, mixed>|null */
    public function issue(string $trigger, string $code, array $ctx): ?array
    {
        return $this->engine->issueEventDocumentQuietly($trigger, [$code], $ctx)[0] ?? null;
    }

    // ---- quote / proposal

    public function quote(string $event, string $quoteId): ?array
    {
        $q = \App\Models\Quote::with(['party', 'offers.carrier.party', 'offers.product'])->find($quoteId);
        if (! $q) {
            return null;
        }
        $offers = $q->offers->whereIn('status', ['OFFERED', 'ACCEPTED'])->sortBy('comparison_rank')->values();
        $best = $offers->first() ?? $q->offers->first();
        $cur = (string) ($q->currency ?: 'XAF');
        $lines = [];
        foreach ($offers as $o) {
            $lines[] = '#'.$o->comparison_rank.' '.$o->carrier?->party?->display_name.' — '.$o->product?->name.' · Prime nette / Net premium: '.MappedFieldValues::money((int) $o->premium_minor, (string) ($o->currency ?: $cur))
                .' · Taxes & frais / Taxes & fees: '.MappedFieldValues::money((int) $o->tax_minor + (int) $o->fee_minor, (string) ($o->currency ?: $cur)).' · Total: '.MappedFieldValues::money((int) $o->total_minor, (string) ($o->currency ?: $cur))
                .' · Validité / Valid until: '.$o->valid_until?->format('Y-m-d');
        }
        $coverages = (array) ($best?->coverage_snapshot['coverages'] ?? []);
        // Subject = the quote revision: an amended quote gets its own document, a repeated request the same one.
        $revision = substr(hash('sha256', json_encode([$q->version, $offers->map->only(['id', 'total_minor', 'status'])->all()])), 0, 12);

        return $this->issue('QUOTE_GENERATED', 'INSURANCE_QUOTE', [
            'tenant_id' => $q->tenant_id, 'carrier_id' => $best?->carrier_id, 'party_id' => $q->party_id, 'currency' => $cur,
            'issuer' => self::tenantIssuer($q->tenant_id), 'language' => self::partyLanguage($q->party_id),
            'subject' => ['type' => 'QUOTE', 'key' => $q->id.':'.$revision, 'label' => (string) ($q->quote_number ?? $q->id)],
            'label' => 'QUOTE / DEVIS '.($q->quote_number ?? ''),
            'sources' => array_filter(['quote_id' => $q->id, 'quote_offer_id' => $best?->id]),
            'fields' => array_filter([
                'quote.reference' => $q->quote_number, 'quote.status' => $q->status, 'quote.revision' => 'v'.(int) $q->version,
                'quote.valid_until' => ($best?->valid_until ?? $q->expires_at)?->toIso8601String(),
                'party.name' => $q->party?->display_name, 'policy.insurer' => $best?->carrier?->party?->display_name, 'policy.product' => $best?->product?->name,
                'policy.insurance_class' => $best?->product?->line_code ?? $q->line_code, 'policy.currency' => $cur, 'premium.currency' => $cur,
                'coverage.lines' => $coverages ?: null, 'premium.net' => $best ? (int) $best->premium_minor : null, 'premium.base' => $best ? (int) ($best->original_premium_minor ?? $best->premium_minor) : null,
                'premium.taxes' => $best ? (int) $best->tax_minor + (int) $best->fee_minor : null, 'premium.gross' => $best ? (int) $best->total_minor : null,
                'risk.summary' => self::facts((array) ($q->risk_facts ?? [])),
                'policy.exclusions_reference' => implode(' · ', array_filter(array_map(fn ($e) => is_array($e) ? ($e['name'] ?? $e['code'] ?? null) : null, (array) ($best?->coverage_snapshot['exclusions'] ?? [])))) ?: null,
            ], fn ($v) => $v !== null && $v !== ''),
            'sections' => [['heading' => 'Offres / Offers', 'paragraphs' => $lines ?: ['—']],
                ['heading' => '', 'paragraphs' => ['Ce devis ne vaut pas attestation d\'assurance. / This quotation is not a certificate of insurance.']]],
            'is_demo' => (bool) ($q->is_demo ?? false),
        ]);
    }

    public function proposal(string $event, string $proposalId): ?array
    {
        $p = \App\Models\Proposal::with(['party', 'offer.carrier.party', 'offer.product', 'offer.quote'])->find($proposalId);
        if (! $p) {
            return null;
        }
        $o = $p->offer;
        $cur = (string) ($o?->currency ?: 'XAF');

        return $this->issue('PROPOSAL_SUBMITTED', 'INSURANCE_PROPOSAL', [
            'tenant_id' => $p->tenant_id, 'carrier_id' => $o?->carrier_id, 'party_id' => $p->party_id, 'currency' => $cur,
            'issuer' => self::tenantIssuer($p->tenant_id), 'language' => self::partyLanguage($p->party_id),
            // One submitted copy per submission (a resubmission after an information request is a new copy).
            'subject' => ['type' => 'PROPOSAL', 'key' => $p->id.':'.(int) ($p->submission_count ?? 1), 'label' => (string) ($p->proposal_number ?? $p->id)],
            'label' => 'PROPOSAL / PROPOSITION '.($p->proposal_number ?? ''),
            'sources' => array_filter(['proposal_id' => $p->id, 'quote_offer_id' => $o?->id, 'quote_id' => $o?->quote_id]),
            'fields' => array_filter([
                'proposal.reference' => $p->proposal_number, 'quote.reference' => $o?->quote?->quote_number, 'party.name' => $p->party?->display_name,
                'proposal.policyholder' => $p->party?->display_name, 'proposal.insured' => $p->party?->display_name,
                'policy.product' => $o?->product?->name, 'policy.insurance_class' => $o?->product?->line_code ?? $o?->quote?->line_code, 'policy.insurer' => $o?->carrier?->party?->display_name,
                'coverage.lines' => (array) ($o?->coverage_snapshot['coverages'] ?? []) ?: null, 'risk.summary' => self::facts((array) ($o?->quote?->risk_facts ?? [])),
                'proposal.premium_estimate' => $o ? (int) $o->total_minor : null, 'proposal.submitted_at' => $p->submitted_at?->toIso8601String(),
                'proposal.disclosures' => self::facts((array) ($p->disclosures ?? [])), 'consent.declaration' => $p->attested_at ? 'Attested / Attestée '.$p->attested_at->format('d/m/Y H:i') : null,
                'proposal.requested_effective_date' => $p->terms_snapshot['coverage_starts_at'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'is_demo' => (bool) ($p->is_demo ?? false),
        ]);
    }

    // ---- finance

    public function payment(string $event, string $paymentId, array $data = []): ?array
    {
        if (($data['status'] ?? null) !== 'SUCCEEDED') {
            return null;
        }

        return $this->paymentReceipt(PaymentIntentRecord::find($paymentId));
    }

    /** PAYMENT_RECONCILED: the platform's receipt of the money (DOC-191), one per succeeded payment. Also the on-demand receipt.pdf. */
    public function paymentReceipt(?PaymentIntentRecord $i): ?array
    {
        if (! $i || $i->status !== 'SUCCEEDED') {
            return null;
        }
        $proposal = $i->proposal_id ? \App\Models\Proposal::with(['party', 'offer.carrier.party', 'offer.product'])->find($i->proposal_id) : null;
        $policy = Policy::where('payment_intent_id', $i->id)->first() ?? ($i->proposal_id ? Policy::where('proposal_id', $i->proposal_id)->orderBy('created_at')->first() : null);
        $cur = (string) ($i->currency ?: 'XAF');

        return $this->issue('PAYMENT_RECONCILED', 'PAYMENT_RECEIPT', [
            'tenant_id' => $i->tenant_id, 'carrier_id' => $proposal?->offer?->carrier_id, 'party_id' => $proposal?->party_id ?? $policy?->party_id, 'currency' => $cur,
            'policy' => $policy, 'payment' => $i, 'issuer' => 'PLATFORM', 'language' => self::partyLanguage($proposal?->party_id ?? $policy?->party_id),
            'subject' => ['type' => 'PAYMENT', 'key' => $i->id, 'label' => (string) $i->provider_reference],
            'label' => 'PAYMENT / PAIEMENT '.$i->provider_reference,
            'sources' => array_filter(['payment_intent_id' => $i->id, 'proposal_id' => $i->proposal_id]),
            'fields' => array_filter([
                'party.name' => $proposal?->party?->display_name, 'policy.insurer' => $proposal?->offer?->carrier?->party?->display_name, 'policy.product' => $proposal?->offer?->product?->name,
                'policy.number' => $policy?->policy_number, 'policy.currency' => $cur, 'payment.reference' => $i->provider_reference, 'payment.amount' => (int) $i->amount_minor,
                'payment.paid_at' => ($i->reconciled_at ?? $i->updated_at)?->toIso8601String(), 'payment.method' => strtoupper(str_replace('_', ' ', (string) $i->provider)), 'payment.status' => 'PAID',
            ], fn ($v) => $v !== null && $v !== ''),
            'is_demo' => (bool) ($i->is_demo ?? false),
        ]);
    }

    public function refund(string $event, string $refundId): ?array
    {
        $r = DB::table('refunds')->where('id', $refundId)->first();
        if (! $r) {
            return null;
        }
        $i = $r->payment_intent_id ? PaymentIntentRecord::find($r->payment_intent_id) : null;
        $policy = $i ? (Policy::where('payment_intent_id', $i->id)->first() ?? ($i->proposal_id ? Policy::where('proposal_id', $i->proposal_id)->orderBy('created_at')->first() : null)) : null;
        $partyId = $policy?->party_id ?? ($i?->proposal_id ? DB::table('proposals')->where('id', $i->proposal_id)->value('party_id') : null);
        $cur = (string) ($r->currency ?: 'XAF');

        return $this->issue('REFUND_APPROVED', 'REFUND_ADVICE', [
            'tenant_id' => $r->tenant_id, 'carrier_id' => $policy?->carrier_id, 'party_id' => $partyId, 'currency' => $cur, 'policy' => $policy, 'payment' => $i,
            'issuer' => $policy ? $this->insurerOr($policy) : self::tenantIssuer($r->tenant_id), 'language' => self::partyLanguage($partyId),
            'subject' => ['type' => 'REFUND', 'key' => $r->id, 'label' => (string) ($r->refund_number ?? $r->id)],
            'label' => 'REFUND / REMBOURSEMENT '.($r->refund_number ?? ''),
            'sources' => array_filter(['refund_id' => $r->id, 'payment_intent_id' => $r->payment_intent_id]),
            'fields' => array_filter([
                'refund.amount' => (int) $r->amount_minor, 'refund.status' => $r->status, 'refund.reference' => $r->refund_number, 'payment.reference' => $i?->provider_reference,
                'payment.method' => $r->payout_method ? strtoupper((string) $r->payout_method) : ($i ? strtoupper((string) $i->provider) : null), 'policy.currency' => $cur,
                'cancellation.reason' => $r->reason_code, 'party.name' => $partyId ? DB::table('parties')->where('id', $partyId)->value('display_name') : null, 'policy.number' => $policy?->policy_number,
            ], fn ($v) => $v !== null && $v !== ''),
            'is_demo' => (bool) ($r->is_demo ?? false),
        ]);
    }

    public function bordereau(string $event, string $bordereauId): ?array
    {
        $b = DB::table('bordereaux')->where('id', $bordereauId)->first();
        if (! $b) {
            return null;
        }
        $code = match ($b->type) {
            'PREMIUM', 'COMMISSION' => 'PREMIUM_BORDEREAU',
            'CLAIM' => 'CLAIMS_BORDEREAU',
            default => 'RISK_BORDEREAU',
        };
        $items = DB::table('bordereau_items as i')->join('policies as p', 'p.id', '=', 'i.policy_id')->leftJoin('parties as pa', 'pa.id', '=', 'p.party_id')
            ->where('i.bordereau_id', $b->id)->orderBy('p.policy_number')->get(['p.id', 'p.policy_number', 'pa.display_name', 'i.transaction_type', 'i.premium_minor', 'i.commission_minor']);
        $cur = (string) ($b->currency ?: 'XAF');
        $claims = $b->type === 'CLAIM' ? DB::table('claims')->whereIn('policy_id', $items->pluck('id'))->whereBetween('submitted_at', [$b->period_start, $b->period_end.' 23:59:59'])->pluck('claim_number')->all() : [];
        $period = \Carbon\Carbon::parse($b->period_start)->format('d/m/Y').' → '.\Carbon\Carbon::parse($b->period_end)->format('d/m/Y');
        $approver = $b->approved_by ?? null;

        return $this->issue('BORDEREAU_APPROVED', $code, [
            'tenant_id' => $b->tenant_id, 'carrier_id' => $b->carrier_id, 'party_id' => null, 'currency' => $cur,
            'issuer' => self::tenantIssuer($b->tenant_id), 'language' => self::userLanguage($approver),
            'subject' => ['type' => 'BORDEREAU', 'key' => $b->id, 'label' => (string) $b->bordereau_number],
            'label' => 'BORDEREAU '.$b->bordereau_number,
            'fields' => array_filter([
                'bordereau.number' => $b->bordereau_number, 'statement.period' => $period, 'policy.period' => $period,
                'policy.effective_from' => \Carbon\Carbon::parse($b->period_start)->toIso8601String(), 'policy.effective_until' => \Carbon\Carbon::parse($b->period_end)->toIso8601String(),
                'policy.number' => $items->pluck('policy_number')->filter()->unique()->implode(' · ') ?: null, 'party.name' => $items->pluck('display_name')->filter()->unique()->implode(' · ') ?: null,
                'premium.gross' => (int) $b->gross_premium_minor, 'statement.written_premium' => (int) $b->gross_premium_minor, 'reinsurance.original_premium' => (int) $b->gross_premium_minor,
                'reinsurance.commission' => (int) $b->commission_minor, 'statement.closing_balance' => (int) ($b->total_amount_minor ?? 0), 'policy.currency' => $cur,
                'policy.insurer' => Carrier::with('party')->find($b->carrier_id)?->party?->display_name, 'claim.number' => $claims ? implode(' · ', $claims) : null,
                'policy.insurance_class' => $b->type,
            ], fn ($v) => $v !== null && $v !== ''),
            'sections' => [['heading' => 'Lignes / Rows', 'paragraphs' => $items->map(fn ($i) => $i->policy_number.' · '.$i->display_name.' · '.$i->transaction_type.' · '
                .MappedFieldValues::money((int) $i->premium_minor, $cur).' · Commission: '.MappedFieldValues::money((int) $i->commission_minor, $cur))->all() ?: ['—']]],
        ]);
    }

    // ---- KYC

    public function kyc(string $event, string $submissionId, array $data = []): ?array
    {
        $s = DB::table('kyc_submissions')->where('id', $submissionId)->first();
        if (! $s) {
            return null;
        }
        $request = $event === 'kyc_submission.information_requested';
        $outcome = match ($event) {
            'kyc_submission.approved' => 'APPROVED / APPROUVÉ',
            'kyc_submission.rejected' => 'REJECTED / REJETÉ',
            'kyc_submission.remediation_requested' => 'REMEDIATION REQUIRED / RÉGULARISATION REQUISE',
            default => 'INFORMATION REQUESTED / INFORMATIONS DEMANDÉES',
        };
        $reason = (string) ($data['reason'] ?? $s->remediation_reason ?? $s->decision_reason ?? '');

        return $this->issue($request ? 'KYC_INFORMATION_REQUESTED' : 'KYC_DECIDED', $request ? 'KYC_REQUEST' : 'KYC_APPROVAL_REMEDIATION_NOTICE', [
            'tenant_id' => $s->tenant_id, 'party_id' => $s->party_id, 'issuer' => self::tenantIssuer($s->tenant_id), 'language' => self::partyLanguage($s->party_id),
            // One letter per decision event of the submission (an approval after remediation is a new letter).
            'subject' => ['type' => 'KYC', 'key' => $s->id.':'.$event.':'.(int) ($s->version ?? 1), 'label' => (string) $s->id],
            'label' => 'KYC '.$outcome,
            'sources' => array_filter(['kyc_submission_id' => $s->id, 'case_id' => $s->case_id]),
            'fields' => array_filter([
                'party.name' => DB::table('parties')->where('id', $s->party_id)->value('display_name'), 'kyc.case_reference' => $s->case_id ?? $s->id,
                'kyc.status_outcome' => $outcome, 'kyc.reason_category' => $reason ?: null, 'documents.required' => $reason ?: null,
                'sla.due_at' => $s->expires_at ? \Carbon\Carbon::parse($s->expires_at)->toIso8601String() : null, 'kyc.level' => $s->kyc_level,
            ], fn ($v) => $v !== null && $v !== ''),
            'is_demo' => (bool) ($s->is_demo ?? false),
        ]);
    }

    // ---- renewal

    public function renewal(string $event, string $caseId): ?array
    {
        $case = DB::table('renewal_cases')->where('id', $caseId)->first();
        $policy = $case ? Policy::with(['carrier.party', 'party', 'proposal.offer.product'])->find($case->policy_id) : null;
        if (! $policy) {
            return null;
        }
        [$trigger, $code] = match ($event) {
            'renewal.due' => ['RENEWAL_DUE', 'RENEWAL_NOTICE'],
            'renewal.quoted' => ['RENEWAL_QUOTED', 'RENEWAL_QUOTE'],
            'renewal.declined' => ['RENEWAL_DECLINED', 'NON_RENEWAL_NOTICE'],
            default => ['POLICY_LAPSED', 'POLICY_EXPIRY_NOTICE'],
        };
        $quote = $case->renewal_quote_id && Schema::hasTable('quotes') ? \App\Models\Quote::with('offers')->find($case->renewal_quote_id) : null;
        $offer = $quote?->offers->sortBy('comparison_rank')->first();
        $cur = (string) ($policy->currency ?: 'XAF');
        $end = $policy->coverage_ends_at;

        return $this->issue($trigger, $code, [
            'tenant_id' => $policy->tenant_id, 'policy' => $policy, 'issuer' => $this->insurerOr($policy), 'language' => self::partyLanguage($policy->party_id),
            'subject' => ['type' => 'RENEWAL', 'key' => $case->id.($trigger === 'RENEWAL_QUOTED' && $quote ? ':'.$quote->id : ''), 'label' => (string) $policy->policy_number],
            'label' => str_replace('_', ' ', $code).' '.$policy->policy_number,
            'sources' => array_filter(['renewal_case_id' => $case->id, 'quote_id' => $quote?->id]),
            'fields' => array_filter([
                'renewal.reference' => $quote?->quote_number ?? $case->id, 'renewal.expiring_policy' => $policy->policy_number, 'renewal.expiry_date' => $end?->toIso8601String(),
                'renewal.period' => $end ? $end->format('d/m/Y').' → '.$end->copy()->addYear()->format('d/m/Y') : null,
                'renewal.premium' => $offer ? (int) $offer->total_minor : null, 'renewal.quote_valid_until' => ($offer?->valid_until ?? $quote?->expires_at)?->toIso8601String(),
                'renewal.payment_deadline' => $case->due_on ? \Carbon\Carbon::parse($case->due_on)->toIso8601String() : $end?->toIso8601String(),
                'renewal.action_required' => 'Renew before the expiry date / Renouveler avant la date d’échéance',
                'renewal.status' => $case->status, 'renewal.non_renewal_reason' => $case->closed_reason, 'renewal.non_renewal_effective_date' => $end?->toIso8601String(),
                'renewal.customer_action' => $trigger === 'RENEWAL_DECLINED' ? 'Arrange alternative cover before expiry / Souscrire une autre couverture avant l’échéance' : null,
                'expiry.coverage_ending' => $end?->toIso8601String(), 'coverage.limits' => $offer ? implode(' · ', array_filter(array_map(fn ($c) => is_array($c) && isset($c['limit_minor'])
                    ? ($c['name'] ?? $c['code'] ?? '').': '.MappedFieldValues::money((int) $c['limit_minor'], $cur) : null, (array) ($offer->coverage_snapshot['coverages'] ?? [])))) ?: null : null,
            ], fn ($v) => $v !== null && $v !== ''),
        ]);
    }

    /** Scalar facts as one readable line. */
    public static function facts(array $facts): ?string
    {
        $scalar = array_filter($facts, fn ($v, $k) => is_scalar($v) && $v !== '' && ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_BOTH);

        return $scalar ? implode(', ', array_map(fn ($k, $v) => str_replace('_', ' ', (string) $k).': '.(is_bool($v) ? ($v ? 'yes' : 'no') : $v), array_keys($scalar), $scalar)) : null;
    }
}
