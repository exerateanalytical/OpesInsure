<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Providers\Portal\ProviderScope;
use App\Application\Providers\ProviderNetworkService;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provider Portal Gap-Free spec v1 — the provider-side workflows that had no canonical home:
 *  - treatment episodes: the canonical clinical record a provider claim is generated from (no manual re-entry);
 *  - reconciliation: insurer payments received by the provider, bulk-allocated across claims (unmatched stays visible);
 *  - disputes: reason-coded disputes on claims / lines / settlements / payments that never leave financial history.
 * Claims themselves stay on the canonical engine (ProviderClaimService); nothing here prices or approves anything.
 */
final class ProviderOperationsService
{
    public const EPISODE_TYPES = ['OUTPATIENT', 'INPATIENT', 'EMERGENCY', 'DAY_CASE', 'PHARMACY', 'LAB'];

    private const ALLOCATABLE = ['APPROVED', 'PARTIALLY_APPROVED', 'PAYABLE', 'PAID'];

    private const OPEN_DISPUTE = ['DRAFT', 'SUBMITTED', 'ACKNOWLEDGED', 'UNDER_REVIEW', 'MORE_INFORMATION_REQUIRED', 'ESCALATED'];

    public function __construct(
        private readonly ProviderAccess $access,
        private readonly ProviderWorkspaceService $workspace,
        private readonly ProviderClaimService $claims,
        private readonly ProviderNetworkService $network,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    // ----------------------------------------------------------------- treatment episodes

    public function openEpisode(string $tenantId, User $user, ProviderScope $s, array $d): object
    {
        $facility = $d['facility_id'] ?? null;
        $this->access->assertFacility($user, $s, $facility);
        if (! empty($d['department_id']) && ! DB::table('provider_departments')->where(['id' => $d['department_id'], 'provider_facility_id' => $facility])->exists()) {
            throw new ApiProblemException('DEPARTMENT_NOT_FOUND', 422, 'The department does not belong to this facility.');
        }
        if (! empty($d['policy_id']) && ! DB::table('policies')->where(['id' => $d['policy_id'], 'tenant_id' => $tenantId])->exists()) {
            throw new ApiProblemException('POLICY_NOT_FOUND', 422, 'Policy not found.');
        }
        $preauth = null;
        if (! empty($d['preauthorization_id'])) {
            $preauth = $this->workspace->assertPreauth($tenantId, $user, $s, $d['preauthorization_id']);
            if (! empty($d['policy_id']) && $preauth->policy_id !== $d['policy_id']) {
                throw new ApiProblemException('PREAUTH_POLICY_MISMATCH', 422, 'The preauthorization is for another policy.');
            }
        }
        $type = strtoupper($d['episode_type']);
        if (! in_array($type, self::EPISODE_TYPES, true)) {
            throw new ApiProblemException('EPISODE_TYPE_UNKNOWN', 422, 'Unknown episode type.');
        }
        $id = (string) Str::uuid();
        DB::table('treatment_episodes')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'episode_number' => 'TE-'.now()->format('Ym').'-'.strtoupper(Str::random(8)), 'provider_profile_id' => $s->providerId,
            'provider_facility_id' => $facility, 'provider_department_id' => $d['department_id'] ?? null, 'policy_id' => $d['policy_id'] ?? $preauth?->policy_id,
            'member_ref' => $d['member_ref'] ?? $preauth?->member_ref, 'preauthorization_id' => $preauth?->id, 'eligibility_check_id' => $d['eligibility_check_id'] ?? null,
            'episode_type' => $type, 'status' => 'OPEN', 'started_on' => CarbonImmutable::parse($d['started_on'] ?? now())->toDateString(),
            'diagnosis_summary' => $d['diagnosis_summary'] ?? null, 'attending_practitioner' => $d['attending_practitioner'] ?? null,
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('provider_portal.treatment_episode.opened', 'treatment_episode', $id, ['provider_id' => $s->providerId, 'preauth_id' => $preauth?->id]);

        return $this->episode($tenantId, $user, $s, $id);
    }

    public function addEpisodeLine(string $tenantId, User $user, ProviderScope $s, string $id, array $l): object
    {
        $e = $this->episodeRow($tenantId, $user, $s, $id);
        if ($e->status !== 'OPEN') {
            throw new ApiProblemException('EPISODE_NOT_OPEN', 409, 'Only an OPEN episode takes new services.');
        }
        $serviceId = $this->serviceId($s, $l);
        $no = (int) DB::table('treatment_episode_lines')->where('treatment_episode_id', $id)->max('line_no') + 1;
        DB::table('treatment_episode_lines')->insert([
            'id' => (string) Str::uuid(), 'treatment_episode_id' => $id, 'line_no' => $no, 'medical_service_id' => $serviceId, 'provider_code' => $l['provider_code'] ?? null,
            'quantity' => (int) ($l['quantity'] ?? 1), 'unit_price_minor' => (int) $l['unit_price_minor'],
            'service_date' => CarbonImmutable::parse($l['service_date'] ?? $e->started_on)->toDateString(), 'performed_by' => $l['performed_by'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->episode($tenantId, $user, $s, $id);
    }

    public function closeEpisode(string $tenantId, User $user, ProviderScope $s, string $id, ?string $endedOn): object
    {
        $e = $this->episodeRow($tenantId, $user, $s, $id);
        if ($e->status !== 'OPEN') {
            throw new ApiProblemException('EPISODE_NOT_OPEN', 409, 'Only an OPEN episode can be closed.');
        }
        if (! DB::table('treatment_episode_lines')->where('treatment_episode_id', $id)->exists()) {
            throw new ApiProblemException('EPISODE_EMPTY', 422, 'Record at least one service before closing the episode.');
        }
        DB::table('treatment_episodes')->where('id', $id)->update(['status' => 'CLOSED', 'ended_on' => CarbonImmutable::parse($endedOn ?? now())->toDateString(), 'updated_at' => now()]);
        $this->audit->record('provider_portal.treatment_episode.closed', 'treatment_episode', $id, ['provider_id' => $s->providerId]);
        $this->outbox->record('provider_portal.treatment_episode.closed', 'treatment_episode', $id, ['episode_id' => $id, 'provider_id' => $s->providerId]);

        return $this->episode($tenantId, $user, $s, $id);
    }

    /**
     * CLOSED → BILLED: the provider claim is generated from the episode (lines, member, policy, preauth, facility) on the
     * canonical engine. The contract is the provider's ACTIVE cashless contract with this insurer in force on the episode
     * start date; none → 409 CONTRACT_REQUIRED (contract/tariff configuration), never an invented price. Each line
     * reports the tariff version in force on its service date, or NO_TARIFF_REVIEW_REQUIRED (manual review).
     */
    public function billEpisode(string $tenantId, User $user, ProviderScope $s, string $id, string $invoiceReference, ?string $contractId): array
    {
        $e = $this->episodeRow($tenantId, $user, $s, $id);
        if ($e->status !== 'CLOSED') {
            throw new ApiProblemException('EPISODE_NOT_CLOSED', 409, 'Close the episode before billing it.');
        }
        $contract = DB::table('provider_contracts')->where(['tenant_id' => $tenantId, 'provider_profile_id' => $e->provider_profile_id, 'status' => 'ACTIVE'])
            ->whereIn('settlement_mode', ['CASHLESS', 'BOTH'])->where('effective_from', '<=', $e->started_on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $e->started_on))
            ->when($contractId, fn ($q, $v) => $q->where('id', $v))->orderByDesc('effective_from')->first()
            ?? throw new ApiProblemException('CONTRACT_REQUIRED', 409, 'No active cashless contract with this insurer covers the episode date: configure the contract/tariff first.');
        $lines = DB::table('treatment_episode_lines')->where('treatment_episode_id', $id)->orderBy('line_no')->get();

        return DB::transaction(function () use ($tenantId, $user, $s, $e, $contract, $lines, $invoiceReference) {
            $claim = $this->claims->create($tenantId, [
                'provider_id' => $e->provider_profile_id, 'contract_id' => $contract->id, 'invoice_reference' => $invoiceReference,
                'service_date' => (string) $e->started_on, 'member_reference' => $e->member_ref, 'policy_id' => $e->policy_id, 'preauth_id' => $e->preauthorization_id,
                'lines' => $lines->map(fn ($l) => ['medical_service_id' => $l->medical_service_id, 'provider_code' => $l->provider_code, 'quantity' => (int) $l->quantity, 'unit_price_minor' => (int) $l->unit_price_minor])->all(),
            ], $user->id);
            DB::table('health_provider_claims')->where('id', $claim->id)->update(['provider_facility_id' => $e->provider_facility_id, 'source_system' => 'TREATMENT_EPISODE']);
            DB::table('treatment_episodes')->where('id', $e->id)->update(['status' => 'BILLED', 'health_provider_claim_id' => $claim->id, 'updated_at' => now()]);
            $this->outbox->record('provider_portal.claim.created', 'health_provider_claim', $claim->id, ['provider_claim_id' => $claim->id, 'episode_id' => $e->id, 'provider_id' => $s->providerId]);
            $tariffs = $lines->map(function ($l) use ($tenantId, $contract) {
                $t = $this->network->priceFor($tenantId, $contract->id, $l->medical_service_id, (string) $l->service_date);

                return ['line_no' => $l->line_no, 'service_date' => (string) $l->service_date, 'tariff_status' => $t ? 'TARIFF_FOUND' : 'NO_TARIFF_REVIEW_REQUIRED',
                    'tariff_version' => $t?->version, 'contracted_price_minor' => $t ? (int) $t->contracted_price_minor : null];
            })->all();

            return ['claim' => $this->workspace->claim($user, $s, $claim->id), 'tariff_resolution' => $tariffs];
        });
    }

    public function episode(string $tenantId, User $user, ProviderScope $s, string $id): object
    {
        $e = $this->episodeRow($tenantId, $user, $s, $id);
        $e->lines = DB::table('treatment_episode_lines as l')->join('medical_services as m', 'm.id', '=', 'l.medical_service_id')->where('l.treatment_episode_id', $id)
            ->orderBy('l.line_no')->select('l.*', 'm.code as service_code', 'm.name as service_name')->get()->all();

        return (object) $this->workspace->redact($user, $s, (array) $e);
    }

    public function episodes(string $tenantId, User $user, ProviderScope $s, array $f): array
    {
        return $this->episodeQuery($tenantId, $user, $s)->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['facility_id'] ?? null, fn ($q, $v) => $q->where('provider_facility_id', $v))
            ->when($f['department_id'] ?? null, fn ($q, $v) => $q->where('provider_department_id', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('started_on', '>=', $v))->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('started_on', '<=', $v))
            ->orderByDesc('created_at')->paginate(min((int) ($f['per_page'] ?? 50), 200))->through(fn ($r) => $this->workspace->redact($user, $s, (array) $r))->toArray();
    }

    private function episodeQuery(string $tenantId, User $user, ProviderScope $s)
    {
        $fac = $this->access->facilityIds($user, $s);
        $full = $this->access->hasFullScope($user, $s);

        return DB::table('treatment_episodes')->where('tenant_id', $tenantId)->whereIn('provider_profile_id', $this->access->organisation($s->providerId))
            ->where(fn ($q) => $q->whereIn('provider_facility_id', $fac)->when($full, fn ($w) => $w->orWhereNull('provider_facility_id')));
    }

    private function episodeRow(string $tenantId, User $user, ProviderScope $s, string $id): object
    {
        return $this->episodeQuery($tenantId, $user, $s)->where('id', $id)->first() ?? throw new ApiProblemException('EPISODE_NOT_FOUND', 404, 'Treatment episode not found.');
    }

    private function serviceId(ProviderScope $s, array $l): string
    {
        if (! empty($l['service_code'])) {
            $id = DB::table('medical_services')->where('code', strtoupper($l['service_code']))->where('status', 'ACTIVE')->value('id');
        } elseif (! empty($l['provider_code'])) {
            $id = $this->network->resolveProviderCode($s->providerId, $l['provider_code'])?->id;
        }

        return $id ?? throw new ApiProblemException('SERVICE_UNKNOWN', 422, 'Unknown medical service / provider code: map it in the service catalogue first.');
    }

    // ----------------------------------------------------------------- provider claim queries

    public function respondToClaimQuery(string $tenantId, User $user, ProviderScope $s, string $claimId, string $response): array
    {
        $c = $this->workspace->claimQuery($user, $s, $tenantId)->where('c.id', $claimId)->first() ?? throw new ApiProblemException('PROVIDER_CLAIM_NOT_FOUND', 404, 'Provider claim not found.');
        if (! in_array($c->status, ['SUBMITTED', 'UNDER_REVIEW', 'DISPUTED'], true)) {
            throw new ApiProblemException('CLAIM_NOT_QUERYABLE', 409, "No insurer query can be answered on a {$c->status} claim.");
        }
        DB::table('health_provider_claim_events')->insert(['id' => (string) Str::uuid(), 'health_provider_claim_id' => $claimId, 'from_status' => $c->status, 'to_status' => $c->status,
            'event' => 'PROVIDER_RESPONSE', 'reason' => $response, 'payload' => '{}', 'actor_id' => $user->id, 'created_at' => now()]);
        $this->audit->record('provider_portal.claim.query_responded', 'health_provider_claim', $claimId, ['provider_id' => $s->providerId]);
        $this->outbox->record('provider_portal.claim.query_responded', 'health_provider_claim', $claimId, ['provider_claim_id' => $claimId, 'provider_id' => $s->providerId]);

        return $this->workspace->claim($user, $s, $claimId);
    }

    // ----------------------------------------------------------------- reconciliation

    /** Records an insurer payment received; with settlement_batch_id it is auto-allocated to the batch's claims. */
    public function recordPayment(string $tenantId, User $user, ProviderScope $s, array $d): object
    {
        $currency = strtoupper($d['currency']);
        if (DB::table('provider_reconciliations')->where(['tenant_id' => $tenantId, 'provider_profile_id' => $s->providerId, 'payment_reference' => $d['payment_reference']])->exists()) {
            throw new ApiProblemException('PAYMENT_DUPLICATE', 409, 'This payment reference is already recorded.');
        }
        $batch = null;
        if (! empty($d['settlement_batch_id'])) {
            $batch = DB::table('health_provider_settlement_batches')->where(['id' => $d['settlement_batch_id'], 'tenant_id' => $tenantId])
                ->whereIn('provider_profile_id', $this->access->organisation($s->providerId))->first() ?? throw new ApiProblemException('SETTLEMENT_NOT_FOUND', 404, 'Settlement not found.');
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $tenantId, $user, $s, $d, $currency, $batch) {
            DB::table('provider_reconciliations')->insert(['id' => $id, 'tenant_id' => $tenantId, 'provider_profile_id' => $s->providerId, 'settlement_batch_id' => $batch?->id,
                'payment_reference' => $d['payment_reference'], 'received_on' => CarbonImmutable::parse($d['received_on'])->toDateString(), 'currency' => $currency,
                'amount_minor' => (int) $d['amount_minor'], 'allocated_minor' => 0, 'status' => 'UNMATCHED_EXTERNAL', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('provider_portal.payment.recorded', 'provider_reconciliation', $id, ['provider_id' => $s->providerId, 'amount_minor' => (int) $d['amount_minor'], 'currency' => $currency]);
            if ($batch) {
                $alloc = $this->workspace->claimQuery($user, $s, $tenantId)->where('c.settlement_batch_id', $batch->id)->where('c.currency', $currency)->orderBy('c.claim_number')
                    ->get(['c.id', 'c.insurer_share_minor'])->map(fn ($c) => ['claim_id' => $c->id, 'amount_minor' => (int) $c->insurer_share_minor])->all();
                $this->allocate($tenantId, $user, $s, $id, $alloc, null, 'AUTO', true);
            }
        });

        return $this->reconciliation($tenantId, $user, $s, $id);
    }

    /** Manual (bulk) allocation of a received payment across claims: reason mandatory, audited; never over-allocates. */
    public function match(string $tenantId, User $user, ProviderScope $s, string $id, array $allocations, string $reason): object
    {
        DB::transaction(fn () => $this->allocate($tenantId, $user, $s, $id, $allocations, $reason, 'MANUAL', false));

        return $this->reconciliation($tenantId, $user, $s, $id);
    }

    private function allocate(string $tenantId, User $user, ProviderScope $s, string $id, array $allocations, ?string $reason, string $type, bool $partialOk): void
    {
        $r = DB::table('provider_reconciliations')->where(['id' => $id, 'tenant_id' => $tenantId])->whereIn('provider_profile_id', $this->access->organisation($s->providerId))
            ->lockForUpdate()->first() ?? throw new ApiProblemException('RECONCILIATION_NOT_FOUND', 404, 'Reconciliation not found.');
        if ($type === 'MANUAL' && trim((string) $reason) === '') {
            throw new ApiProblemException('MATCH_REASON_REQUIRED', 422, 'A manual match needs a reason.');
        }
        $left = (int) $r->amount_minor - (int) $r->allocated_minor;
        $total = 0;
        foreach ($allocations as $a) {
            $amount = (int) $a['amount_minor'];
            $c = $this->workspace->claimQuery($user, $s, $tenantId)->where('c.id', $a['claim_id'])->first();
            if (! $c) {
                throw new ApiProblemException('PROVIDER_CLAIM_NOT_FOUND', 404, 'Provider claim not found.');
            }
            if ($c->currency !== $r->currency || ! in_array($c->status, self::ALLOCATABLE, true)) {
                throw new ApiProblemException('CLAIM_NOT_ALLOCATABLE', 422, "Claim {$c->claim_number} is not an approved {$r->currency} claim.");
            }
            $already = (int) DB::table('provider_reconciliation_lines')->where('health_provider_claim_id', $c->id)->sum('amount_minor');
            $open = (int) $c->insurer_share_minor - $already;
            if ($partialOk) {
                $amount = min($amount, $open, $left - $total);
            }
            if ($amount <= 0 && $partialOk) {
                continue;
            }
            if ($amount <= 0 || $amount > $open) {
                throw new ApiProblemException('ALLOCATION_EXCEEDS_CLAIM', 422, "Claim {$c->claim_number} has {$open} left to allocate.");
            }
            $total += $amount;
            if ($total > $left) {
                throw new ApiProblemException('ALLOCATION_EXCEEDS_PAYMENT', 422, "Only {$left} of the payment is unallocated.");
            }
            DB::table('provider_reconciliation_lines')->insert(['id' => (string) Str::uuid(), 'provider_reconciliation_id' => $id, 'health_provider_claim_id' => $c->id,
                'amount_minor' => $amount, 'match_type' => $type, 'reason' => $reason, 'created_by' => $user->id, 'created_at' => now()]);
        }
        $allocated = (int) $r->allocated_minor + $total;
        $status = $allocated === 0 ? 'UNMATCHED_EXTERNAL' : ($allocated === (int) $r->amount_minor ? 'MATCHED' : 'PARTIALLY_MATCHED');
        DB::table('provider_reconciliations')->where('id', $id)->update(['allocated_minor' => $allocated, 'status' => $status, 'updated_at' => now()]);
        $this->audit->record('provider_portal.reconciliation.matched', 'provider_reconciliation', $id, ['provider_id' => $s->providerId, 'match_type' => $type, 'allocated_minor' => $total, 'reason' => $reason]);
        if ($total > 0) {
            $this->outbox->record('provider_portal.reconciliation.matched', 'provider_reconciliation', $id, ['reconciliation_id' => $id, 'provider_id' => $s->providerId, 'allocated_minor' => $total, 'status' => $status]);
        }
    }

    public function reconciliation(string $tenantId, User $user, ProviderScope $s, string $id): object
    {
        $r = DB::table('provider_reconciliations')->where(['id' => $id, 'tenant_id' => $tenantId])->whereIn('provider_profile_id', $this->access->organisation($s->providerId))->first()
            ?? throw new ApiProblemException('RECONCILIATION_NOT_FOUND', 404, 'Reconciliation not found.');
        $r->unallocated_minor = (int) $r->amount_minor - (int) $r->allocated_minor;
        $r->lines = DB::table('provider_reconciliation_lines as l')->join('health_provider_claims as c', 'c.id', '=', 'l.health_provider_claim_id')->where('l.provider_reconciliation_id', $id)
            ->orderBy('l.created_at')->select('l.*', 'c.claim_number', 'c.invoice_reference')->get()->all();

        return $r;
    }

    public function reconciliations(string $tenantId, User $user, ProviderScope $s, array $f): array
    {
        return DB::table('provider_reconciliations')->where('tenant_id', $tenantId)->whereIn('provider_profile_id', $this->access->organisation($s->providerId))
            ->when($f['reconciliation_status'] ?? $f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('received_on', '>=', $v))->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('received_on', '<=', $v))
            ->orderByDesc('received_on')->paginate(min((int) ($f['per_page'] ?? 50), 200))
            ->through(fn ($r) => (array) $r + ['unallocated_minor' => (int) $r->amount_minor - (int) $r->allocated_minor])->toArray();
    }

    // ----------------------------------------------------------------- disputes

    public function openDispute(string $tenantId, User $user, ProviderScope $s, array $d): object
    {
        $type = strtoupper($d['subject_type']);
        $reason = strtoupper($d['reason_code']);
        if (! in_array($reason, ProviderWorkspaceRegister::DISPUTE_REASONS, true)) {
            throw new ApiProblemException('DISPUTE_REASON_REQUIRED', 422, 'A dispute needs a valid reason code.');
        }
        $claim = null;
        $currency = $d['currency'] ?? null;
        if (in_array($type, ['CLAIM', 'CLAIM_LINE'], true)) {
            $claim = $this->workspace->claimQuery($user, $s, $tenantId)->where('c.id', $d['claim_id'] ?? null)->first() ?? throw new ApiProblemException('PROVIDER_CLAIM_NOT_FOUND', 404, 'Provider claim not found.');
            if ($type === 'CLAIM_LINE' && ! DB::table('health_provider_claim_lines')->where(['health_provider_claim_id' => $claim->id, 'line_no' => $d['claim_line_no'] ?? 0])->exists()) {
                throw new ApiProblemException('CLAIM_LINE_NOT_FOUND', 404, 'Claim line not found.');
            }
            $currency = $claim->currency;
        } elseif ($type === 'SETTLEMENT') {
            $b = DB::table('health_provider_settlement_batches')->where(['id' => $d['settlement_batch_id'] ?? null, 'tenant_id' => $tenantId])->whereIn('provider_profile_id', $this->access->organisation($s->providerId))->first()
                ?? throw new ApiProblemException('SETTLEMENT_NOT_FOUND', 404, 'Settlement not found.');
            $currency = $b->currency;
        } elseif ($type === 'RECONCILIATION') {
            $currency = $this->reconciliation($tenantId, $user, $s, (string) ($d['reconciliation_id'] ?? ''))->currency;
        } else {
            throw new ApiProblemException('DISPUTE_SUBJECT_UNKNOWN', 422, 'subject_type is CLAIM, CLAIM_LINE, SETTLEMENT or RECONCILIATION.');
        }

        return DB::transaction(function () use ($tenantId, $user, $s, $d, $type, $reason, $claim, $currency) {
            $id = (string) Str::uuid();
            DB::table('provider_disputes')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'dispute_number' => 'PD-'.now()->format('Ym').'-'.strtoupper(Str::random(8)), 'provider_profile_id' => $s->providerId,
                'subject_type' => $type, 'health_provider_claim_id' => $claim?->id, 'claim_line_no' => $type === 'CLAIM_LINE' ? (int) $d['claim_line_no'] : null,
                'settlement_batch_id' => $type === 'SETTLEMENT' ? $d['settlement_batch_id'] : null, 'provider_reconciliation_id' => $type === 'RECONCILIATION' ? $d['reconciliation_id'] : null,
                'reason_code' => $reason, 'disputed_amount_minor' => (int) ($d['disputed_amount_minor'] ?? 0), 'currency' => $currency, 'description' => $d['description'],
                'status' => 'SUBMITTED', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            // A contested adjudication also moves the canonical claim to DISPUTED (PROVIDER_DISPUTE case) — history is kept on both.
            if ($claim && in_array($claim->status, ['APPROVED', 'PARTIALLY_APPROVED', 'REJECTED'], true)) {
                $this->claims->dispute($tenantId, $claim->id, $reason.': '.$d['description'], $user);
            }
            $this->audit->record('provider_portal.dispute.opened', 'provider_dispute', $id, ['provider_id' => $s->providerId, 'subject_type' => $type, 'reason_code' => $reason]);
            $this->outbox->record('provider_portal.dispute.opened', 'provider_dispute', $id, ['dispute_id' => $id, 'provider_id' => $s->providerId, 'reason_code' => $reason]);

            return $this->dispute($tenantId, $user, $s, $id);
        });
    }

    public function dispute(string $tenantId, User $user, ProviderScope $s, string $id): object
    {
        return DB::table('provider_disputes')->where(['id' => $id, 'tenant_id' => $tenantId])->whereIn('provider_profile_id', $this->access->organisation($s->providerId))->first()
            ?? throw new ApiProblemException('DISPUTE_NOT_FOUND', 404, 'Dispute not found.');
    }

    public function disputes(string $tenantId, User $user, ProviderScope $s, array $f): array
    {
        return DB::table('provider_disputes')->where('tenant_id', $tenantId)->whereIn('provider_profile_id', $this->access->organisation($s->providerId))
            ->when($f['dispute_status'] ?? $f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderByDesc('created_at')
            ->paginate(min((int) ($f['per_page'] ?? 50), 200))->toArray();
    }

    /** Insurer-side resolution (back office). The dispute row stays; only its status/resolution change. */
    public function resolveDispute(string $tenantId, string $id, string $status, ?int $amount, string $response, User $actor): object
    {
        if (! in_array($status, ['ACKNOWLEDGED', 'UNDER_REVIEW', 'MORE_INFORMATION_REQUIRED', 'RESOLVED_PROVIDER', 'RESOLVED_INSURER', 'PARTIALLY_RESOLVED', 'ESCALATED', 'CLOSED'], true)) {
            throw new ApiProblemException('DISPUTE_STATUS_INVALID', 422, 'Invalid dispute status.');
        }
        $d = DB::table('provider_disputes')->where(['id' => $id, 'tenant_id' => $tenantId])->first() ?? throw new ApiProblemException('DISPUTE_NOT_FOUND', 404, 'Dispute not found.');
        if (! in_array($d->status, self::OPEN_DISPUTE, true)) {
            throw new ApiProblemException('DISPUTE_CLOSED', 409, 'This dispute is already resolved.');
        }
        $final = in_array($status, ['RESOLVED_PROVIDER', 'RESOLVED_INSURER', 'PARTIALLY_RESOLVED', 'CLOSED'], true);
        DB::table('provider_disputes')->where('id', $id)->update(['status' => $status, 'response' => $response, 'resolution_amount_minor' => $amount,
            'resolved_at' => $final ? now() : null, 'resolved_by' => $final ? $actor->id : null, 'updated_at' => now()]);
        $this->audit->record('provider_portal.dispute.resolved', 'provider_dispute', $id, ['provider_id' => $d->provider_profile_id, 'status' => $status, 'resolution_amount_minor' => $amount]);
        if ($final) {
            $this->outbox->record('provider_portal.dispute.resolved', 'provider_dispute', $id, ['dispute_id' => $id, 'provider_id' => $d->provider_profile_id, 'status' => $status]);
        }

        return DB::table('provider_disputes')->find($id);
    }
}
