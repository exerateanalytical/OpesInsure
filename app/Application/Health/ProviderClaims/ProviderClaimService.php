<?php

declare(strict_types=1);

namespace App\Application\Health\ProviderClaims;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Application\Events\OutboxWriter;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\Ledger\FinancialPostingService;
use App\Application\Providers\Portal\ProviderScope;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Claim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * REQ-HLT-003 — provider claims (cashless billing).
 *
 * DRAFT → SUBMITTED → UNDER_REVIEW → APPROVED | PARTIALLY_APPROVED | REJECTED → PAYABLE → PAID, with DISPUTED reachable from
 * an adjudicated (not yet payable) claim. Each invoice line is priced against the contract's approved tariff (13A
 * ProviderNetworkService::priceFor) and carries its own explanation of benefits.
 *
 * Member-level history: when the invoice names a policy, submission opens a Claim on the claims engine (source of the
 * member's claim history, fraud, reporting); line adjudication, EOB, provider payables and settlement stay here — the
 * claims engine settles one claimant, a provider invoice settles a provider across many members.
 */
final class ProviderClaimService
{
    public const STATUSES = ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'PARTIALLY_APPROVED', 'REJECTED', 'PAYABLE', 'PAID', 'DISPUTED'];

    /** from => allowed targets */
    public const TRANSITIONS = [
        'DRAFT' => ['SUBMITTED'],
        'SUBMITTED' => ['UNDER_REVIEW'],
        'UNDER_REVIEW' => ['APPROVED', 'PARTIALLY_APPROVED', 'REJECTED'],
        'APPROVED' => ['PAYABLE', 'DISPUTED'],
        'PARTIALLY_APPROVED' => ['PAYABLE', 'DISPUTED'],
        'REJECTED' => ['DISPUTED'],
        'DISPUTED' => ['UNDER_REVIEW', 'APPROVED', 'PARTIALLY_APPROVED', 'REJECTED'],
        'PAYABLE' => ['PAID'],
        'PAID' => [],
    ];

    /** Batch 14 E3 preauthorization / guarantee-of-payment service (called guarded, Wave A contract). */
    private const PREAUTH_SERVICE = 'App\\Application\\Health\\Preauth\\PreauthorizationService';

    /** E2 eligibility outcomes that reject a line (same set as preauthorization). */
    public const BLOCKING_OUTCOMES = ['NOT_ELIGIBLE', 'WAITING_PERIOD', 'BENEFIT_EXHAUSTED'];

    public function __construct(
        private readonly ProviderNetworkService $network,
        private readonly ProviderRegistry $providers,
        private readonly ProviderClaimPricer $pricer,
        private readonly ObligationService $obligations,
        private readonly FinancialPostingService $posting,
        private readonly CaseService $cases,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * @param array{provider_id:string, contract_id:string, invoice_reference:string, service_date:string, member_party_id?:?string, member_reference?:?string,
     *              policy_id?:?string, preauth_id?:?string, lines:list<array{medical_service_id?:?string, provider_code?:?string, quantity?:int, unit_price_minor:int}>} $d
     */
    public function create(string $tenantId, array $d, ?string $actorId): object
    {
        $provider = $this->providers->find($d['provider_id']);
        ProviderScope::assertMayActFor($actorId ? User::find($actorId) : null, $provider->id);
        $contract = $this->network->contract($tenantId, $d['contract_id']);
        if ($contract->provider_profile_id !== $provider->id) {
            throw new ApiProblemException('CONTRACT_PROVIDER_MISMATCH', 422, 'The contract does not belong to this provider.');
        }
        if ($contract->status !== 'ACTIVE' || $contract->settlement_mode === 'REIMBURSEMENT') {
            throw new ApiProblemException('CONTRACT_NOT_CASHLESS', 409, 'Cashless billing needs an ACTIVE contract with a CASHLESS or BOTH settlement mode.');
        }
        if (empty($d['member_party_id']) && empty($d['member_reference']) && empty($d['policy_id'])) {
            throw new ApiProblemException('MEMBER_REQUIRED', 422, 'Identify the member (member_party_id, member_reference or policy_id).');
        }
        if (! empty($d['policy_id']) && ! DB::table('policies')->where(['id' => $d['policy_id'], 'tenant_id' => $tenantId])->exists()) {
            throw new ApiProblemException('POLICY_NOT_FOUND', 422, 'Policy not found.');
        }
        if (($d['lines'] ?? []) === []) {
            throw new ApiProblemException('INVOICE_EMPTY', 422, 'A provider claim needs at least one invoice line.');
        }
        if (DB::table('health_provider_claims')->where(['tenant_id' => $tenantId, 'provider_profile_id' => $provider->id, 'invoice_reference' => $d['invoice_reference']])->exists()) {
            throw new ApiProblemException('INVOICE_DUPLICATE', 409, 'This provider invoice was already billed.');
        }
        $lines = [];
        foreach (array_values($d['lines']) as $i => $l) {
            $serviceId = $l['medical_service_id'] ?? null;
            if (! $serviceId && ! empty($l['provider_code'])) {
                $serviceId = $this->network->resolveProviderCode($provider->id, $l['provider_code'])?->id;
            }
            if (! $serviceId || ! DB::table('medical_services')->where('id', $serviceId)->exists()) {
                throw new ApiProblemException('SERVICE_UNKNOWN', 422, 'Line '.($i + 1).': unknown medical service / provider code.');
            }
            $qty = (int) ($l['quantity'] ?? 1);
            if ($qty < 1 || (int) $l['unit_price_minor'] < 0) {
                throw new ApiProblemException('LINE_INVALID', 422, 'Line '.($i + 1).': quantity ≥ 1 and a non-negative unit price.');
            }
            $lines[] = ['line_no' => $i + 1, 'medical_service_id' => $serviceId, 'provider_code' => $l['provider_code'] ?? null, 'quantity' => $qty, 'unit_price_minor' => (int) $l['unit_price_minor']];
        }

        return DB::transaction(function () use ($tenantId, $d, $actorId, $provider, $lines): object {
            $id = (string) Str::uuid();
            $currency = DB::table('provider_tariff_versions')->where('provider_contract_id', $d['contract_id'])->whereIn('status', ['APPROVED', 'SUPERSEDED'])
                ->orderByDesc('version')->value('currency') ?? ($d['currency'] ?? 'XAF');
            DB::table('health_provider_claims')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'claim_number' => 'HPC-'.now()->format('Ym').'-'.strtoupper(Str::random(8)),
                'provider_profile_id' => $provider->id, 'provider_contract_id' => $d['contract_id'], 'invoice_reference' => $d['invoice_reference'],
                'member_party_id' => $d['member_party_id'] ?? null, 'member_reference' => $d['member_reference'] ?? null, 'policy_id' => $d['policy_id'] ?? null,
                'preauth_id' => $d['preauth_id'] ?? null, 'service_date' => CarbonImmutable::parse($d['service_date'])->toDateString(), 'currency' => strtoupper($currency),
                'status' => 'DRAFT', 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $billed = 0;
            foreach ($lines as $l) {
                $lineBilled = $l['quantity'] * $l['unit_price_minor'];
                $billed += $lineBilled;
                DB::table('health_provider_claim_lines')->insert($l + [
                    'id' => (string) Str::uuid(), 'health_provider_claim_id' => $id, 'billed_minor' => $lineBilled, 'rejected_minor' => $lineBilled,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('health_provider_claims')->where('id', $id)->update(['billed_minor' => $billed, 'rejected_minor' => 0]);
            $this->event($id, null, 'DRAFT', 'CREATED', null, $actorId);
            $this->audit->record('health.provider_claim.created', 'health_provider_claim', $id, ['provider_id' => $provider->id, 'invoice_reference' => $d['invoice_reference'], 'billed_minor' => $billed]);

            return $this->find($tenantId, $id);
        });
    }

    /** DRAFT → SUBMITTED: eligibility, preauth link, contracted pricing (proposed EOB) and the member-level Claim. */
    public function submit(string $tenantId, string $id, ?string $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $id, $actorId): object {
            $c = $this->locked($tenantId, $id);
            ProviderScope::assertMayActFor($actorId ? User::find($actorId) : null, $c->provider_profile_id);
            $this->assertTransition($c, 'SUBMITTED');
            $preauthCheck = $this->verifyPreauth($tenantId, $c);
            $preauthVerified = $preauthCheck === 'VERIFIED';
            $this->priceLines($tenantId, $c, [], $preauthVerified);
            $claimId = $c->claim_id ?? $this->linkMemberClaim($tenantId, $c);
            DB::table('health_provider_claims')->where('id', $id)->update(['status' => 'SUBMITTED', 'submitted_at' => now(), 'preauth_verified' => $preauthVerified, 'preauth_check' => $preauthCheck,
                'claim_id' => $claimId, 'updated_at' => now()]);
            $this->event($id, 'DRAFT', 'SUBMITTED', 'SUBMITTED', null, $actorId, ['claim_id' => $claimId, 'preauth_verified' => $preauthVerified, 'preauth_check' => $preauthCheck]);
            $this->audit->record('health.provider_claim.submitted', 'health_provider_claim', $id, ['claim_id' => $claimId]);
            $this->outbox->record('health.provider_claim.submitted', 'health_provider_claim', $id, ['provider_claim_id' => $id, 'provider_id' => $c->provider_profile_id, 'claim_id' => $claimId, 'billed_minor' => (int) $c->billed_minor]);

            return $this->find($tenantId, $id);
        });
    }

    public function startReview(string $tenantId, string $id, ?string $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $id, $actorId): object {
            $c = $this->locked($tenantId, $id);
            $this->assertTransition($c, 'UNDER_REVIEW');
            $this->move($c, 'UNDER_REVIEW', 'REVIEW_STARTED', null, $actorId);

            return $this->find($tenantId, $id);
        });
    }

    /**
     * UNDER_REVIEW → APPROVED | PARTIALLY_APPROVED | REJECTED. Overrides can only reduce what the tariff allows.
     *
     * @param  list<array{line_no:int, reject?:bool, reason_code?:?string, allowed_minor?:?int, explanation?:?string}>  $overrides
     */
    public function adjudicate(string $tenantId, string $id, array $overrides, ?string $note, ?string $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $id, $overrides, $note, $actorId): object {
            $c = $this->locked($tenantId, $id);
            if ($c->status !== 'UNDER_REVIEW') {
                throw new ApiProblemException('PROVIDER_CLAIM_STATE', 409, "Only an UNDER_REVIEW provider claim can be adjudicated (is {$c->status}).");
            }
            if ($actorId !== null && $actorId === $c->created_by) {
                throw new ApiProblemException('MAKER_CHECKER', 403, 'The user who captured the invoice cannot adjudicate it.');
            }
            $byLine = [];
            foreach ($overrides as $o) {
                if (! empty($o['reject']) && ! in_array($o['reason_code'] ?? null, ProviderClaimPricer::REASONS, true)) {
                    throw new ApiProblemException('REASON_REQUIRED', 422, 'A rejected line needs a reason code: '.implode(', ', ProviderClaimPricer::REASONS).'.');
                }
                $byLine[(int) $o['line_no']] = $o;
            }
            $totals = $this->priceLines($tenantId, $c, $byLine, (bool) $c->preauth_verified);
            $to = $totals['allowed_minor'] === 0 ? 'REJECTED' : ($totals['rejected_minor'] > 0 ? 'PARTIALLY_APPROVED' : 'APPROVED');
            DB::table('health_provider_claims')->where('id', $id)->update(['adjudicated_at' => now(), 'adjudicated_by' => $actorId, 'adjudication_note' => $note]);
            $this->move($c, $to, 'ADJUDICATED', $note, $actorId, $totals);
            if ($c->claim_id) {
                DB::table('claims')->where('id', $c->claim_id)->update(['approved_amount_minor' => $totals['insurer_share_minor'], 'updated_at' => now()]);
            }
            $this->audit->record('health.provider_claim.adjudicated', 'health_provider_claim', $id, ['decision' => $to] + $totals);
            $this->outbox->record('health.provider_claim.adjudicated', 'health_provider_claim', $id, ['provider_claim_id' => $id, 'decision' => $to] + $totals);
            app(\App\Application\Providers\Workspace\ProviderDocumentService::class)->onClaimAdjudicated($tenantId, $id, ($actorId ? \App\Models\User::find($actorId) : null)); // D4: DOC-072 explanation of benefits

            return $this->find($tenantId, $id);
        });
    }

    /**
     * APPROVED | PARTIALLY_APPROVED → PAYABLE: the insurer share becomes a PAYABLE CLAIM obligation to the provider party,
     * posts health.provider_claim.approved and consumes the member's benefits (BenefitAccumulator, when present).
     */
    public function markPayable(string $tenantId, string $id, ?string $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $id, $actorId): object {
            $c = $this->locked($tenantId, $id);
            $this->assertTransition($c, 'PAYABLE');
            $amount = (int) $c->insurer_share_minor;
            if ($amount <= 0) {
                throw new ApiProblemException('NOTHING_PAYABLE', 409, 'The insurer share is zero; nothing is payable to the provider.');
            }
            $provider = $this->providers->find($c->provider_profile_id);
            $o = $this->obligations->create([
                'tenant_id' => $tenantId, 'kind' => 'PAYABLE', 'type' => 'CLAIM', 'source_type' => 'health_provider_claim', 'source_id' => $c->id,
                'currency' => $c->currency, 'amount_minor' => $amount, 'due_at' => now(), 'creditor_type' => 'party', 'creditor_id' => $provider->party_id,
                'policy_id' => $c->policy_id, 'description' => "Provider claim {$c->claim_number} (invoice {$c->invoice_reference})",
                'metadata' => ['provider_id' => $provider->id, 'claim_id' => $c->claim_id],
            ], $actorId);
            $journal = $this->posting->post($tenantId, 'health.provider_claim.approved', $c->id, $amount, $c->currency, 'health_provider_claim:'.$c->id);
            $gop = $this->consumeGuarantee($tenantId, $c, $amount);
            $benefits = $this->consumeBenefits($tenantId, $c);
            DB::table('health_provider_claims')->where('id', $id)->update(['financial_obligation_id' => $o->id, 'preauth_consumed_minor' => $gop]);
            $this->move($c, 'PAYABLE', 'MADE_PAYABLE', null, $actorId, ['financial_obligation_id' => $o->id, 'journal_id' => $journal, 'gop_consumed_minor' => $gop, 'benefits' => $benefits]);
            $this->outbox->record('health.provider_claim.payable', 'health_provider_claim', $id, ['provider_claim_id' => $id, 'financial_obligation_id' => $o->id, 'amount_minor' => $amount, 'currency' => $c->currency]);

            return $this->find($tenantId, $id);
        });
    }

    /** Provider contests an adjudication (before it is payable): opens a PROVIDER_DISPUTE case on the case engine. */
    public function dispute(string $tenantId, string $id, string $reason, ?User $actor): object
    {
        return DB::transaction(function () use ($tenantId, $id, $reason, $actor): object {
            $c = $this->locked($tenantId, $id);
            ProviderScope::assertMayActFor($actor, $c->provider_profile_id);
            $this->assertTransition($c, 'DISPUTED');
            $case = $this->cases->open($tenantId, 'PROVIDER_DISPUTE', [
                'title' => "Provider dispute — {$c->claim_number} (invoice {$c->invoice_reference})",
                'subject_type' => 'health_provider_claim', 'subject_id' => $c->id, // no source: cases.source is unique and a claim may be disputed more than once
                'idempotency_key' => 'hpc-dispute:'.$c->id.':'.DB::table('health_provider_claim_events')->where(['health_provider_claim_id' => $c->id, 'event' => 'DISPUTED'])->count(),
            ], $actor);
            DB::table('health_provider_claims')->where('id', $id)->update(['status_before_dispute' => $c->status, 'dispute_case_id' => $case->id, 'dispute_reason' => $reason]);
            $this->move($c, 'DISPUTED', 'DISPUTED', $reason, $actor?->id, ['case_id' => $case->id]);
            $this->outbox->record('health.provider_claim.disputed', 'health_provider_claim', $id, ['provider_claim_id' => $id, 'case_id' => $case->id]);

            return $this->find($tenantId, $id);
        });
    }

    /** REOPEN sends the claim back to UNDER_REVIEW for re-adjudication; UPHOLD restores the contested decision. */
    public function resolveDispute(string $tenantId, string $id, string $outcome, string $reason, ?string $actorId): object
    {
        if (! in_array($outcome, ['REOPEN', 'UPHOLD'], true)) {
            throw new ApiProblemException('OUTCOME_INVALID', 422, 'Outcome must be REOPEN or UPHOLD.');
        }

        return DB::transaction(function () use ($tenantId, $id, $outcome, $reason, $actorId): object {
            $c = $this->locked($tenantId, $id);
            if ($c->status !== 'DISPUTED') {
                throw new ApiProblemException('PROVIDER_CLAIM_STATE', 409, "The provider claim is not disputed (is {$c->status}).");
            }
            $to = $outcome === 'REOPEN' ? 'UNDER_REVIEW' : $c->status_before_dispute;
            $this->move($c, $to, 'DISPUTE_'.$outcome, $reason, $actorId, ['case_id' => $c->dispute_case_id]);
            $this->outbox->record('health.provider_claim.dispute_resolved', 'health_provider_claim', $id, ['provider_claim_id' => $id, 'outcome' => $outcome, 'case_id' => $c->dispute_case_id]);

            return $this->find($tenantId, $id);
        });
    }

    public function find(string $tenantId, string $id): object
    {
        $c = DB::table('health_provider_claims')->where(['tenant_id' => $tenantId, 'id' => $id])->first()
            ?? throw new ApiProblemException('PROVIDER_CLAIM_NOT_FOUND', 404, 'Provider claim not found.');
        $c->lines = DB::table('health_provider_claim_lines as l')->join('medical_services as s', 's.id', '=', 'l.medical_service_id')
            ->where('l.health_provider_claim_id', $id)->orderBy('l.line_no')->select('l.*', 's.code as service_code')->get()->all();
        $c->history = DB::table('health_provider_claim_events')->where('health_provider_claim_id', $id)->orderBy('created_at')->get()->all();

        return $c;
    }

    /** Explanation of benefits: per line billed / allowed / copay / insurer share / member share / rejected + reason. */
    public function eob(string $tenantId, string $id): array
    {
        $c = $this->find($tenantId, $id);

        return [
            'provider_claim_id' => $c->id, 'claim_number' => $c->claim_number, 'invoice_reference' => $c->invoice_reference, 'status' => $c->status, 'currency' => $c->currency,
            'lines' => array_map(fn ($l) => [
                'line_no' => $l->line_no, 'service_code' => $l->service_code, 'quantity' => $l->quantity, 'billed_minor' => (int) $l->billed_minor,
                'allowed_minor' => (int) $l->allowed_minor, 'copay_minor' => (int) $l->copay_minor, 'insurer_share_minor' => (int) $l->insurer_share_minor,
                'member_share_minor' => (int) $l->member_share_minor, 'rejected_minor' => (int) $l->rejected_minor, 'decision' => $l->decision,
                'reason_code' => $l->reason_code, 'explanation' => $l->explanation, 'tariff_version' => $l->tariff_version,
            ], $c->lines),
            'totals' => ['billed_minor' => (int) $c->billed_minor, 'allowed_minor' => (int) $c->allowed_minor, 'copay_minor' => (int) $c->copay_minor,
                'insurer_share_minor' => (int) $c->insurer_share_minor, 'member_share_minor' => (int) $c->member_share_minor, 'rejected_minor' => (int) $c->rejected_minor],
        ];
    }

    public function list(string $tenantId, array $filters): array
    {
        return DB::table('health_provider_claims')->where('tenant_id', $tenantId)
            ->when($filters['provider_id'] ?? null, fn ($q, $v) => $q->where('provider_profile_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')->limit(200)->get()->all();
    }

    /** Called by ProviderSettlementService inside its transaction. */
    public function markPaid(object $c, string $batchId, ?string $actorId): void
    {
        $this->assertTransition($c, 'PAID');
        $journal = $this->posting->post($c->tenant_id, 'health.provider_claim.paid', $c->id, (int) $c->insurer_share_minor, $c->currency, 'health_provider_claim:'.$c->id);
        DB::table('health_provider_claims')->where('id', $c->id)->update(['paid_at' => now()]);
        $this->move($c, 'PAID', 'PAID', null, $actorId, ['settlement_batch_id' => $batchId, 'journal_id' => $journal]);
        $this->outbox->record('health.provider_claim.paid', 'health_provider_claim', $c->id, ['provider_claim_id' => $c->id, 'settlement_batch_id' => $batchId, 'amount_minor' => (int) $c->insurer_share_minor, 'currency' => $c->currency]);
    }

    // ----------------------------------------------------------------- internals

    /** @return array{billed_minor:int, allowed_minor:int, copay_minor:int, insurer_share_minor:int, member_share_minor:int, rejected_minor:int} */
    private function priceLines(string $tenantId, object $c, array $overrides, bool $preauthVerified): array
    {
        $totals = ['billed_minor' => 0, 'allowed_minor' => 0, 'copay_minor' => 0, 'insurer_share_minor' => 0, 'member_share_minor' => 0, 'rejected_minor' => 0];
        $lines = DB::table('health_provider_claim_lines as l')->join('medical_services as s', 's.id', '=', 'l.medical_service_id')
            ->where('l.health_provider_claim_id', $c->id)->orderBy('l.line_no')->select('l.*', 's.code as service_code')->get();
        foreach ($lines as $l) {
            $tariff = $this->network->priceFor($tenantId, $c->provider_contract_id, $l->medical_service_id, $c->service_date);
            $o = $overrides[(int) $l->line_no] ?? [];
            $reject = ! empty($o['reject']) ? $o['reason_code'] : null;
            $note = $o['explanation'] ?? null;
            $elig = $this->eligibility($tenantId, $c, $l->service_code);
            if ($reject === null && $elig['blocking']) {
                [$reject, $note] = [$elig['outcome'] === 'BENEFIT_EXHAUSTED' ? 'BENEFIT_LIMIT' : 'NOT_ELIGIBLE', 'Member not eligible for this service on the service date ('.$elig['outcome'].').'];
            }
            $eob = $this->pricer->price((int) $l->quantity, (int) $l->unit_price_minor, $tariff, isset($o['allowed_minor']) ? (int) $o['allowed_minor'] : null, $reject, $note);
            DB::table('health_provider_claim_lines')->where('id', $l->id)->update($eob + [
                'eligibility_outcome' => $elig['outcome'], 'coverage_code' => $elig['coverage_code'], 'benefit_code' => $elig['benefit_code'],
                'tariff_line_id' => $tariff?->id, 'tariff_version' => $tariff?->version, 'tariff_unit_price_minor' => $tariff?->contracted_price_minor, 'updated_at' => now(),
            ]);
            $totals['billed_minor'] += (int) $l->billed_minor;
            foreach (['allowed_minor', 'copay_minor', 'insurer_share_minor', 'member_share_minor', 'rejected_minor'] as $k) {
                $totals[$k] += $eob[$k];
            }
        }
        DB::table('health_provider_claims')->where('id', $c->id)->update($totals + ['updated_at' => now()]);

        return $totals;
    }

    /**
     * E2 EligibilityService when deployed (Wave A contract keys: outcome / eligible, coverage.code, benefit_code; blocking
     * outcomes as for preauthorization); otherwise the policy must be in force on the service date.
     *
     * @return array{outcome: string, blocking: bool, coverage_code: ?string, benefit_code: ?string, health_member_id: ?string}
     */
    private function eligibility(string $tenantId, object $c, string $serviceCode): array
    {
        $svc = 'App\\Application\\Health\\Eligibility\\EligibilityService';
        if (class_exists($svc)) {
            $r = app($svc)->check($tenantId, (string) ($c->member_reference ?? $c->member_party_id ?? $c->policy_id), $c->provider_profile_id, $serviceCode, $c->service_date,
                'PROVIDER_CLAIM', null, $c->policy_id);
            $outcome = (string) ($r['outcome'] ?? (($r['eligible'] ?? true) ? 'ELIGIBLE' : 'NOT_ELIGIBLE'));

            return ['outcome' => $outcome, 'blocking' => in_array($outcome, self::BLOCKING_OUTCOMES, true), 'coverage_code' => $r['coverage']['code'] ?? null,
                'benefit_code' => $r['benefit_code'] ?? null, 'health_member_id' => $r['health_member_id'] ?? null];
        }
        $ok = true;
        if ($c->policy_id) { // member identified without a policy: eligibility is the adjudicator's call
            $p = DB::table('policies')->where(['id' => $c->policy_id, 'tenant_id' => $tenantId])->first();
            $d = CarbonImmutable::parse($c->service_date)->endOfDay();
            $ok = $p && in_array($p->status, ['ACTIVE', 'EXPIRING', 'ENDORSEMENT_PENDING'], true)
                && ($p->coverage_starts_at === null || CarbonImmutable::parse($p->coverage_starts_at)->startOfDay()->lte($d))
                && ($p->coverage_ends_at === null || CarbonImmutable::parse($p->coverage_ends_at)->gte(CarbonImmutable::parse($c->service_date)->startOfDay()));
        }

        return ['outcome' => $ok ? 'ELIGIBLE' : 'NOT_ELIGIBLE', 'blocking' => ! $ok, 'coverage_code' => null, 'benefit_code' => null, 'health_member_id' => null];
    }

    /**
     * Verifies the guarantee of payment against E3's health_preauthorizations (PreauthorizationService::guaranteeFor:
     * approved, validity window, provider, policy, amount left). Returns VERIFIED, NONE (no preauth given / module not
     * deployed) or the refusal reason; an unverified GOP leaves the claim adjudicable without a guarantee.
     */
    private function verifyPreauth(string $tenantId, object $c): string
    {
        if (! $c->preauth_id) {
            return 'NONE';
        }
        if (! class_exists(self::PREAUTH_SERVICE) || ! Schema::hasTable('health_preauthorizations')) {
            return 'NONE'; // preauth module not deployed: reference kept, unverified
        }

        return app(self::PREAUTH_SERVICE)->guaranteeFor($tenantId, (string) $c->preauth_id, $c->provider_profile_id, $c->policy_id, (string) $c->service_date)['reason'];
    }

    /** Payable time: draw the insurer share from the verified GOP (E3). @return int amount taken from the guarantee */
    private function consumeGuarantee(string $tenantId, object $c, int $amount): int
    {
        if (! $c->preauth_verified || ! $c->preauth_id || ! class_exists(self::PREAUTH_SERVICE)) {
            return 0;
        }
        $svc = app(self::PREAUTH_SERVICE);
        // Re-check at payable time: the GOP may have been cancelled or consumed by another invoice since submission.
        if (! $svc->guaranteeFor($tenantId, (string) $c->preauth_id, $c->provider_profile_id, $c->policy_id, (string) $c->service_date)['verified']) {
            return 0;
        }

        return $svc->consumeGuarantee($tenantId, (string) $c->preauth_id, $amount, $c->id);
    }

    private function linkMemberClaim(string $tenantId, object $c): ?string
    {
        if (! $c->policy_id) {
            return null;
        }
        $policy = DB::table('policies')->where('id', $c->policy_id)->first();
        $claim = Claim::create([
            'tenant_id' => $tenantId, 'policy_id' => $c->policy_id, 'claimant_party_id' => $c->member_party_id ?? $policy->party_id,
            'claim_number' => 'CLM-'.now()->format('Ym').'-'.strtoupper(Str::random(10)), 'status' => 'SUBMITTED',
            'loss_occurred_at' => CarbonImmutable::parse($c->service_date), 'loss_location' => null, 'currency' => $c->currency,
            'loss_details' => ['description' => "Cashless health care billed by provider (invoice {$c->invoice_reference}).", 'type' => 'HEALTH_PROVIDER_CLAIM', 'provider_claim_id' => $c->id, 'provider_id' => $c->provider_profile_id,
                // E5 contract: a CASHLESS claim's benefits are consumed by the provider-claim flow, never by the reimbursement hook.
                'health' => ['channel' => 'CASHLESS', 'member_ref' => $c->member_reference ?? $c->member_party_id, 'event_ref' => 'health_provider_claim:'.$c->id, 'preauth_id' => $c->preauth_id]],
            'estimated_loss_minor' => (int) DB::table('health_provider_claim_lines')->where('health_provider_claim_id', $c->id)->sum('billed_minor'),
            'submitted_at' => now(),
        ]);
        $this->audit->record('claim.provider_billed', 'claim', $claim->id, ['provider_claim_id' => $c->id]);

        return $claim->id;
    }

    /**
     * E5 BenefitAccumulator when deployed (guarded): each paid line consumes the benefit E2 resolved for it (fallback: the
     * medical service category) for the member E2 resolved. Under a verified GOP the consumption draws E3's reservation
     * (holder preauth:<id>) first. Money now owed to the provider is always recorded (allow_overrun). A product with no
     * benefit schedule accumulates nothing. The result is recorded in the claim history.
     */
    private function consumeBenefits(string $tenantId, object $c): array
    {
        $acc = 'App\\Application\\Health\\Benefits\\BenefitAccumulator';
        if (! class_exists($acc) || ! method_exists($acc, 'consume')) {
            return ['status' => 'SKIPPED', 'reason' => 'BenefitAccumulator not deployed'];
        }
        $out = [];
        foreach (DB::table('health_provider_claim_lines as l')->join('medical_services as s', 's.id', '=', 'l.medical_service_id')
            ->where('l.health_provider_claim_id', $c->id)->where('l.insurer_share_minor', '>', 0)->select('l.*', 's.code as service_code', 's.category_code')->orderBy('l.line_no')->get() as $l) {
            $benefit = (string) ($l->benefit_code ?? $l->category_code);
            $member = $this->benefitMember($tenantId, $c, $l->service_code);
            $options = ['claim_id' => $c->claim_id, 'event_ref' => 'health_provider_claim:'.$c->id, 'reference_type' => 'health_provider_claim_line', 'reference_id' => $l->id,
                'idempotency_key' => 'hpc-line:'.$l->id, 'reason' => 'Cashless provider claim '.$c->claim_number, 'allow_overrun' => true];
            if ($c->preauth_verified && $c->preauth_id) {
                $options['holder'] = 'preauth:'.$c->preauth_id;
            }
            try {
                $r = app($acc)->consume($tenantId, $member, $benefit, (int) $l->insurer_share_minor, CarbonImmutable::parse($c->service_date), $options);
                $out[] = ['line_no' => $l->line_no, 'benefit_code' => $benefit, 'status' => 'CONSUMED', 'group_id' => $r['group_id'] ?? null, 'overrun' => (bool) ($r['overrun'] ?? false)];
            } catch (\App\Application\Health\Benefits\BenefitRefused $e) {
                if ($e->reasonCode !== 'schedule_not_found') {
                    throw $e;
                }
                $out[] = ['line_no' => $l->line_no, 'benefit_code' => $benefit, 'status' => 'NO_SCHEDULE'];
            }
        }

        return ['status' => 'DONE', 'lines' => $out];
    }

    /** E5 member argument: E2's health member id when resolved, else the member reference given on the invoice. */
    private function benefitMember(string $tenantId, object $c, string $serviceCode): array
    {
        $svc = 'App\\Application\\Health\\Eligibility\\EligibilityService';
        $given = $c->member_reference ?? $c->member_party_id;
        $ref = $given !== null && class_exists($svc) && method_exists($svc, 'memberFor') ? app($svc)->memberFor($tenantId, (string) $given, $c->policy_id)?->id : null;
        if ($ref === null && $c->preauth_verified && $c->preauth_id && class_exists(self::PREAUTH_SERVICE)) {
            $pa = DB::table('health_preauthorizations')->where('id', $c->preauth_id)->first();
            if ($pa) {
                return \App\Application\Health\Preauth\PreauthorizationService::benefitMember($pa); // same accumulator as the reservation
            }
        }

        return ['member_ref' => (string) ($ref ?? $c->member_reference ?? $c->member_party_id ?? $c->policy_id), 'policy_id' => $c->policy_id];
    }

    private function locked(string $tenantId, string $id): object
    {
        return DB::table('health_provider_claims')->where(['tenant_id' => $tenantId, 'id' => $id])->lockForUpdate()->first()
            ?? throw new ApiProblemException('PROVIDER_CLAIM_NOT_FOUND', 404, 'Provider claim not found.');
    }

    private function assertTransition(object $c, string $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$c->status] ?? [], true)) {
            throw new ApiProblemException('PROVIDER_CLAIM_TRANSITION', 409, "A {$c->status} provider claim cannot move to {$to}.");
        }
    }

    private function move(object $c, string $to, string $event, ?string $reason, ?string $actorId, array $payload = []): void
    {
        $this->assertTransition($c, $to);
        DB::table('health_provider_claims')->where('id', $c->id)->update(['status' => $to, 'updated_at' => now()]);
        $this->event($c->id, $c->status, $to, $event, $reason, $actorId, $payload);
        $this->audit->record('health.provider_claim.transitioned', 'health_provider_claim', $c->id, ['from' => $c->status, 'to' => $to, 'event' => $event]);
    }

    private function event(string $id, ?string $from, string $to, string $event, ?string $reason, ?string $actorId, array $payload = []): void
    {
        DB::table('health_provider_claim_events')->insert([
            'id' => (string) Str::uuid(), 'health_provider_claim_id' => $id, 'from_status' => $from, 'to_status' => $to, 'event' => $event,
            'reason' => $reason, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'actor_id' => $actorId, 'created_at' => now(),
        ]);
    }
}
