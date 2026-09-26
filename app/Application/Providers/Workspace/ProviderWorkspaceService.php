<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Health\Eligibility\EligibilityService;
use App\Application\Health\Preauth\PreauthorizationService;
use App\Application\Providers\Portal\ProviderScope;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Provider Portal Gap-Free spec v1 — provider-side reads and point-of-care eligibility (REQ-PRV-003, REQ-HLT-001).
 *
 * Every read is scoped to the active provider (ProviderScope) and to the user's facilities (ProviderAccess). Financial
 * truth is DERIVED from transactions (health_provider_claims, lines, settlement batches, reconciliations, disputes):
 * there is no editable provider balance. Clinical fields are stripped for non-clinical roles (data minimisation).
 */
final class ProviderWorkspaceService
{
    private const APPROVED = ['APPROVED', 'PARTIALLY_APPROVED', 'PAYABLE', 'PAID'];

    private const OUTSTANDING = ['APPROVED', 'PARTIALLY_APPROVED', 'PAYABLE'];

    private const CLINICAL_FIELDS = ['clinical_notes', 'diagnosis_summary', 'type_details', 'decision_notes'];

    public function __construct(
        private readonly ProviderAccess $access,
        private readonly EligibilityService $eligibility,
        private readonly PreauthorizationService $preauths,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    // ----------------------------------------------------------------- eligibility & benefits

    /**
     * Point-of-care check. An engine failure (insurer outage) returns INSURER_SYSTEM_UNAVAILABLE with eligible=false —
     * never a false approval. Medical history is never returned; only what the provider needs to serve the patient.
     *
     * @param array{member_ref: string, search_method?: ?string, policy_id?: ?string, service_code: string, service_date?: ?string, facility_id?: ?string} $d
     */
    public function checkEligibility(string $tenantId, User $user, ProviderScope $s, array $d): array
    {
        $this->access->assertFacility($user, $s, $d['facility_id'] ?? null);
        try {
            // Savepoint: an engine failure never leaves a half-written check behind.
            $r = DB::transaction(fn () => $this->eligibility->check($tenantId, $d['member_ref'], $s->providerId, $d['service_code'], $d['service_date'] ?? null, 'PROVIDER_PORTAL', $user->id, $d['policy_id'] ?? null));
        } catch (ApiProblemException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->audit->record('provider_portal.eligibility.unavailable', 'provider', $s->providerId, ['provider_id' => $s->providerId, 'error' => class_basename($e)]);

            return ['verification_reference' => null, 'coverage_status' => 'UNKNOWN', 'eligible' => false, 'failure_states' => ['INSURER_SYSTEM_UNAVAILABLE'],
                'ui_state' => 'INSURER_UNAVAILABLE', 'message' => 'The insurer system is unavailable: nothing is approved. Verify manually.'];
        }

        $out = $this->presentEligibility($tenantId, $s, $r['check_id'], $r);
        $this->outbox->record('provider_portal.eligibility.checked', 'health_eligibility_check', $r['check_id'], ['provider_id' => $s->providerId, 'coverage_status' => $out['coverage_status']]);

        return $out;
    }

    public function eligibilityResult(string $tenantId, ProviderScope $s, string $checkId): array
    {
        $row = DB::table('health_eligibility_checks')->where(['id' => $checkId, 'tenant_id' => $tenantId, 'provider_profile_id' => $s->providerId])->first()
            ?? throw new ApiProblemException('ELIGIBILITY_CHECK_NOT_FOUND', 404, 'Verification not found.');

        return $this->presentEligibility($tenantId, $s, $row->id, ['outcome' => $row->outcome, 'eligible' => $row->outcome === 'ELIGIBLE',
            'reasons' => json_decode((string) $row->reasons, true) ?: [], 'service_date' => (string) $row->service_date, 'policy_id' => $row->policy_id,
            'health_member_id' => $row->health_member_id, 'benefit_code' => $row->benefit_code, 'service' => ['code' => $row->service_code]]);
    }

    private function presentEligibility(string $tenantId, ProviderScope $s, string $checkId, array $r): array
    {
        $codes = array_column($r['reasons'] ?? [], 'code');
        $failures = array_values(array_unique(array_map(fn ($c) => ProviderWorkspaceRegister::FAILURE_STATES[$c] ?? 'MANUAL_VERIFICATION_REQUIRED', $codes)));
        $policy = ! empty($r['policy_id']) ? DB::table('policies')->where('id', $r['policy_id'])->first(['id', 'carrier_id', 'coverage_starts_at', 'coverage_ends_at', 'status']) : null;
        $serviceCode = $r['service']['code'] ?? null;
        $service = $serviceCode ? DB::table('medical_services')->where('code', $serviceCode)->first() : null;
        $tariffPreauth = $service ? DB::table('provider_tariff_lines as l')->join('provider_tariff_versions as v', 'v.id', '=', 'l.provider_tariff_version_id')
            ->join('provider_contracts as c', 'c.id', '=', 'v.provider_contract_id')->where('c.tenant_id', $tenantId)->where('c.provider_profile_id', $s->providerId)
            ->where('v.status', 'APPROVED')->where('l.medical_service_id', $service->id)->value('l.preauthorization_required') : null;
        $preauthRequired = (bool) ($tariffPreauth ?? $service?->preauthorization_required_default ?? false);
        if ($preauthRequired && ($r['eligible'] ?? false)) {
            $failures[] = 'PREAUTH_REQUIRED';
        }
        $network = in_array('PROVIDER_OUT_OF_NETWORK', $failures, true) ? 'OUT_OF_NETWORK' : (in_array('NETWORK_NOT_CONFIGURED', $codes, true) ? 'UNKNOWN' : 'IN_NETWORK');
        $status = ($r['eligible'] ?? false) ? ($preauthRequired ? 'ELIGIBLE_PREAUTH_REQUIRED' : 'ELIGIBLE')
            : (($r['outcome'] ?? null) === 'REVIEW_REQUIRED' ? 'MANUAL_VERIFICATION_REQUIRED' : 'NOT_ELIGIBLE');
        $out = [
            'verification_reference' => $checkId, 'coverage_status' => $status, 'eligible' => (bool) ($r['eligible'] ?? false), 'engine_outcome' => $r['outcome'] ?? null,
            'failure_states' => $failures, 'reasons' => array_map(fn ($x) => ['code' => $x['code'], 'message' => $x['message'] ?? null], $r['reasons'] ?? []),
            'ui_state' => $status === 'MANUAL_VERIFICATION_REQUIRED' ? 'MANUAL_REVIEW_REQUIRED' : 'SUCCESS',
            'member_id' => $r['health_member_id'] ?? null, 'member' => isset($r['member']) ? array_intersect_key((array) $r['member'], array_flip(['member_number', 'display_name', 'relationship'])) : null,
            'policy_id' => $policy?->id, 'insurer_id' => $tenantId, 'carrier_id' => $policy?->carrier_id,
            'effective_from' => $policy?->coverage_starts_at, 'effective_until' => $policy?->coverage_ends_at,
            'provider_network_status' => $network, 'benefit_code' => $r['benefit_code'] ?? null, 'service_date' => $r['service_date'] ?? null,
            'waiting_period_status' => in_array('WAITING_PERIOD', $codes, true) ? ['in_waiting_period' => true, 'ends_on' => $r['waiting_period_ends_on'] ?? null] : ['in_waiting_period' => false],
            'coverage' => $r['coverage'] ?? null, 'preauthorization_required' => $preauthRequired,
        ];
        return $out;
    }

    // ----------------------------------------------------------------- preauthorizations & admissions (canonical: health_preauthorizations)

    public function preauthQuery(string $tenantId, User $user, ProviderScope $s): Builder
    {
        $fac = $this->access->facilityIds($user, $s);
        $full = $this->access->hasFullScope($user, $s);

        return DB::table('health_preauthorizations')->where('tenant_id', $tenantId)->whereIn('provider_profile_id', $this->access->organisation($s->providerId))
            ->where(fn ($q) => $q->whereIn('provider_facility_id', $fac)->when($full, fn ($w) => $w->orWhereNull('provider_facility_id')));
    }

    public function preauthorizations(string $tenantId, User $user, ProviderScope $s, array $f): array
    {
        return $this->preauthQuery($tenantId, $user, $s)
            ->when($f['status'] ?? $f['preauth_status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['request_type'] ?? null, fn ($q, $v) => $q->where('request_type', $v))
            ->when($f['facility_id'] ?? null, fn ($q, $v) => $q->where('provider_facility_id', $v))
            ->when($f['policy_id'] ?? null, fn ($q, $v) => $q->where('policy_id', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('service_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('service_date', '<=', $v))
            ->orderByDesc('created_at')->paginate(min((int) ($f['per_page'] ?? 50), 200))
            ->through(fn ($r) => $this->redact($user, $s, (array) $r))->toArray();
    }

    public function preauthorization(string $tenantId, User $user, ProviderScope $s, string $id): array
    {
        $row = $this->preauthQuery($tenantId, $user, $s)->where('id', $id)->first() ?? throw new ApiProblemException('PREAUTH_NOT_FOUND', 404, 'Preauthorization not found.');
        $data = $this->preauths->present($row, true);
        foreach ($data['history'] ?? [] as $i => $h) {
            unset($data['history'][$i]['payload']); // reviewer internals stay insurer-side
        }

        return $this->redact($user, $s, $data);
    }

    /** Asserts the preauth exists in scope (404 otherwise); used before delegating a provider-side mutation. */
    public function assertPreauth(string $tenantId, User $user, ProviderScope $s, string $id, ?string $type = null): object
    {
        $row = $this->preauthQuery($tenantId, $user, $s)->where('id', $id)->when($type, fn ($q, $v) => $q->where('request_type', $v))->first();

        return $row ?? throw new ApiProblemException($type === 'ADMISSION' ? 'ADMISSION_NOT_FOUND' : 'PREAUTH_NOT_FOUND', 404, 'Not found.');
    }

    // ----------------------------------------------------------------- claims & invoices (canonical: health_provider_claims)

    public function claimQuery(User $user, ProviderScope $s, ?string $tenantId = null): Builder
    {
        $fac = $this->access->facilityIds($user, $s);
        $full = $this->access->hasFullScope($user, $s);

        return DB::table('health_provider_claims as c')->whereIn('c.provider_profile_id', $this->access->organisation($s->providerId))
            ->when($tenantId, fn ($q, $t) => $q->where('c.tenant_id', $t))
            ->where(fn ($q) => $q->whereIn('c.provider_facility_id', $fac)->when($full, fn ($w) => $w->orWhereNull('c.provider_facility_id')));
    }

    /** Canonical filters on provider claims (spec filters). */
    public function filteredClaims(User $user, ProviderScope $s, array $f): Builder
    {
        return $this->claimQuery($user, $s)
            ->when($f['insurer_id'] ?? null, fn ($q, $v) => $q->where('c.tenant_id', $v))
            ->when($f['facility_id'] ?? null, fn ($q, $v) => $q->where('c.provider_facility_id', $v))
            ->when($f['patient_id'] ?? $f['member_id'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('c.member_reference', $v)->when($this->uuidOrNull($v), fn ($x, $id) => $x->orWhere('c.member_party_id', $id))))
            ->when($f['policy_id'] ?? null, fn ($q, $v) => $q->where('c.policy_id', $v))
            ->when($f['claim_status'] ?? $f['status'] ?? null, fn ($q, $v) => $q->where('c.status', $v))
            ->when($f['invoice_status'] ?? null, fn ($q, $v) => $q->where('c.status', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('c.service_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('c.service_date', '<=', $v))
            ->when(isset($f['amount_min']), fn ($q) => $q->where('c.billed_minor', '>=', (int) $f['amount_min']))
            ->when(isset($f['amount_max']), fn ($q) => $q->where('c.billed_minor', '<=', (int) $f['amount_max']))
            ->when($f['payment_status'] ?? null, fn ($q, $v) => $v === 'PAID' ? $q->where('c.status', 'PAID') : $q->where('c.status', '<>', 'PAID'))
            ->when($f['settlement_status'] ?? null, fn ($q, $v) => $q->whereIn('c.settlement_batch_id', DB::table('health_provider_settlement_batches')->where('status', $v)->select('id')))
            ->when($f['service_code'] ?? null, fn ($q, $v) => $q->whereExists(fn ($e) => $e->from('health_provider_claim_lines as l')->join('medical_services as m', 'm.id', '=', 'l.medical_service_id')
                ->whereColumn('l.health_provider_claim_id', 'c.id')->where('m.code', strtoupper($v))))
            ->when($f['service_family'] ?? null, fn ($q, $v) => $q->whereExists(fn ($e) => $e->from('health_provider_claim_lines as l')->join('medical_services as m', 'm.id', '=', 'l.medical_service_id')
                ->whereColumn('l.health_provider_claim_id', 'c.id')->where('m.service_family', strtoupper($v))))
            ->when($f['aging_bucket'] ?? null, fn ($q, $v) => $this->agingWhere($q, $v));
    }

    public function claims(User $user, ProviderScope $s, array $f): array
    {
        return $this->filteredClaims($user, $s, $f)->orderByDesc('c.created_at')->select('c.*')->paginate(min((int) ($f['per_page'] ?? 50), 200))
            ->through(fn ($r) => $this->publicClaim($r))->toArray();
    }

    public function claim(User $user, ProviderScope $s, string $id): array
    {
        $c = $this->claimQuery($user, $s)->where('c.id', $id)->first() ?? throw new ApiProblemException('PROVIDER_CLAIM_NOT_FOUND', 404, 'Provider claim not found.');
        $data = $this->publicClaim($c);
        $data['lines'] = DB::table('health_provider_claim_lines as l')->join('medical_services as m', 'm.id', '=', 'l.medical_service_id')->where('l.health_provider_claim_id', $id)
            ->orderBy('l.line_no')->select('l.*', 'm.code as service_code', 'm.name as service_name')->get()
            ->map(fn ($l) => (array) $l + ['deduction_reason_code' => (int) $l->rejected_minor > 0 || (int) $l->copay_minor > 0
                ? (ProviderWorkspaceRegister::DEDUCTION_REASONS[$l->reason_code ?? ((int) $l->copay_minor > 0 ? 'COPAY' : 'OTHER')] ?? 'OTHER_REVIEWED_REASON') : null])->all();
        $data['history'] = DB::table('health_provider_claim_events')->where('health_provider_claim_id', $id)->orderBy('created_at')
            ->get(['from_status', 'to_status', 'event', 'reason', 'created_at'])->all();
        $data['disputes'] = DB::table('provider_disputes')->where('health_provider_claim_id', $id)->orderBy('created_at')->get()->all();
        $data['allocations'] = DB::table('provider_reconciliation_lines as rl')->join('provider_reconciliations as r', 'r.id', '=', 'rl.provider_reconciliation_id')
            ->where('rl.health_provider_claim_id', $id)->select('rl.id', 'rl.amount_minor', 'rl.match_type', 'r.payment_reference', 'r.received_on')->get()->all();

        return $data;
    }

    private function publicClaim(object $c): array
    {
        $d = (array) $c;
        unset($d['adjudication_note']); // internal adjudication note stays insurer-side (EOB carries the per-line reasons)
        $d['insurer_id'] = $c->tenant_id;

        return $d;
    }

    // ----------------------------------------------------------------- accounts (derived — never editable)

    /** One counterparty account per insurer (tenant) the provider works with, with the spec KPIs per currency. */
    public function accounts(User $user, ProviderScope $s, array $f = []): array
    {
        $rows = $this->filteredClaims($user, $s, $f)->where('c.status', '<>', 'DRAFT')->groupBy('c.tenant_id', 'c.currency')->select('c.tenant_id', 'c.currency')
            ->selectRaw("SUM(c.billed_minor) as submitted_minor,
                SUM(CASE WHEN c.status IN ('APPROVED','PARTIALLY_APPROVED','PAYABLE','PAID') THEN c.insurer_share_minor ELSE 0 END) as approved_minor,
                SUM(CASE WHEN c.status IN ('SUBMITTED','UNDER_REVIEW') THEN c.billed_minor ELSE 0 END) as pending_minor,
                SUM(CASE WHEN c.status IN ('APPROVED','PARTIALLY_APPROVED','REJECTED','PAYABLE','PAID','DISPUTED') THEN c.rejected_minor ELSE 0 END) as rejected_minor,
                SUM(CASE WHEN c.status = 'PAYABLE' THEN c.insurer_share_minor ELSE 0 END) as payable_minor,
                SUM(CASE WHEN c.status = 'PAID' THEN c.insurer_share_minor ELSE 0 END) as paid_minor,
                SUM(CASE WHEN c.status IN ('APPROVED','PARTIALLY_APPROVED','PAYABLE') THEN c.insurer_share_minor ELSE 0 END) as outstanding_minor,
                SUM(CASE WHEN c.status = 'DISPUTED' THEN c.billed_minor - c.insurer_share_minor ELSE 0 END) as disputed_claims_minor,
                SUM(c.member_share_minor) as patient_share_minor,
                AVG(CASE WHEN c.paid_at IS NOT NULL AND c.submitted_at IS NOT NULL THEN EXTRACT(EPOCH FROM (c.paid_at - c.submitted_at)) / 86400 END) as avg_settlement_days")
            ->orderBy('c.tenant_id')->get();
        $names = DB::table('tenants')->whereIn('id', $rows->pluck('tenant_id')->unique())->pluck('legal_name', 'id');
        $org = $this->access->organisation($s->providerId);

        return $rows->map(function ($r) use ($names, $org) {
            $disputes = (int) DB::table('provider_disputes')->where('tenant_id', $r->tenant_id)->whereIn('provider_profile_id', $org)
                ->whereNotIn('status', ['RESOLVED_PROVIDER', 'RESOLVED_INSURER', 'PARTIALLY_RESOLVED', 'CLOSED'])->where('currency', $r->currency)->sum('disputed_amount_minor');
            $received = DB::table('provider_reconciliations')->where('tenant_id', $r->tenant_id)->whereIn('provider_profile_id', $org)->where('currency', $r->currency)
                ->selectRaw('COALESCE(SUM(amount_minor),0) as received, COALESCE(SUM(allocated_minor),0) as allocated')->first();

            return [
                'insurer_id' => $r->tenant_id, 'insurer_name' => $names[$r->tenant_id] ?? null, 'currency' => $r->currency,
                'submitted_amount' => (int) $r->submitted_minor, 'approved_amount' => (int) $r->approved_minor, 'pending_amount' => (int) $r->pending_minor,
                'rejected_amount' => (int) $r->rejected_minor, 'queried_amount' => 0, 'payable_amount' => (int) $r->payable_minor, 'paid_amount' => (int) $r->paid_minor,
                'outstanding_amount' => (int) $r->outstanding_minor, 'disputed_amount' => $disputes + (int) $r->disputed_claims_minor,
                'patient_share_amount' => (int) $r->patient_share_minor, 'average_settlement_days' => $r->avg_settlement_days === null ? null : round((float) $r->avg_settlement_days, 1),
                'payments_received' => (int) $received->received, 'reconciliation_difference' => (int) $received->received - (int) $received->allocated,
                'balance_source' => 'DERIVED_FROM_TRANSACTIONS', 'editable' => false,
            ];
        })->all();
    }

    /** Insurer account detail: KPIs, aging and the entries that make the balance (drill-down to claim lines and allocations). */
    public function account(User $user, ProviderScope $s, string $insurerId, array $f = []): array
    {
        $f['insurer_id'] = $insurerId;
        $kpis = $this->accounts($user, $s, $f);
        if ($kpis === []) {
            throw new ApiProblemException('ACCOUNT_NOT_FOUND', 404, 'No account with this insurer.');
        }

        return ['kpis' => $kpis, 'aging' => $this->aging($user, $s, $f), 'entries' => $this->entries($user, $s, $f)];
    }

    /** Account entries, derived: CLAIM_SUBMITTED, DEDUCTION (with reason code), CLAIM_PAYABLE, SETTLEMENT, PAYMENT_ALLOCATED, DISPUTE. */
    public function entries(User $user, ProviderScope $s, array $f): array
    {
        $claims = $this->filteredClaims($user, $s, $f)->where('c.status', '<>', 'DRAFT')->orderBy('c.submitted_at')->select('c.*')->limit(1000)->get();
        $ids = $claims->pluck('id')->all();
        $out = [];
        foreach ($claims as $c) {
            $out[] = ['type' => 'CLAIMS_SUBMITTED', 'claim_id' => $c->id, 'claim_number' => $c->claim_number, 'invoice_reference' => $c->invoice_reference, 'amount' => (int) $c->billed_minor, 'currency' => $c->currency, 'at' => $c->submitted_at];
            if (in_array($c->status, self::APPROVED, true)) {
                $out[] = ['type' => 'CLAIMS_APPROVED', 'claim_id' => $c->id, 'claim_number' => $c->claim_number, 'amount' => (int) $c->insurer_share_minor, 'currency' => $c->currency, 'at' => $c->adjudicated_at];
                if ((int) $c->member_share_minor > 0) {
                    $out[] = ['type' => 'PATIENT_SHARE', 'claim_id' => $c->id, 'claim_number' => $c->claim_number, 'amount' => (int) $c->member_share_minor, 'currency' => $c->currency, 'at' => $c->adjudicated_at];
                }
            }
            if ($c->status === 'PAID') {
                $out[] = ['type' => 'SETTLEMENT', 'claim_id' => $c->id, 'claim_number' => $c->claim_number, 'settlement_batch_id' => $c->settlement_batch_id, 'amount' => (int) $c->insurer_share_minor, 'currency' => $c->currency, 'at' => $c->paid_at];
            }
        }
        foreach (DB::table('health_provider_claim_lines')->whereIn('health_provider_claim_id', $ids)->where('rejected_minor', '>', 0)->orderBy('line_no')->get() as $l) {
            $out[] = ['type' => 'DEDUCTIONS', 'claim_id' => $l->health_provider_claim_id, 'line_no' => $l->line_no, 'amount' => (int) $l->rejected_minor,
                'reason_code' => ProviderWorkspaceRegister::DEDUCTION_REASONS[$l->reason_code ?? 'OTHER'] ?? 'OTHER_REVIEWED_REASON', 'adjudication_reason' => $l->reason_code, 'explanation' => $l->explanation];
        }
        foreach (DB::table('provider_reconciliation_lines as rl')->join('provider_reconciliations as r', 'r.id', '=', 'rl.provider_reconciliation_id')->whereIn('rl.health_provider_claim_id', $ids)
            ->select('rl.*', 'r.payment_reference', 'r.currency')->get() as $a) {
            $out[] = ['type' => 'PAYMENTS_RECEIVED', 'claim_id' => $a->health_provider_claim_id, 'reconciliation_line_id' => $a->id, 'payment_reference' => $a->payment_reference, 'amount' => (int) $a->amount_minor, 'currency' => $a->currency, 'at' => $a->created_at];
        }
        foreach (DB::table('provider_disputes')->whereIn('health_provider_claim_id', $ids)->get() as $d) {
            $out[] = ['type' => 'DISPUTED_AMOUNT', 'claim_id' => $d->health_provider_claim_id, 'dispute_id' => $d->id, 'status' => $d->status, 'amount' => (int) $d->disputed_amount_minor, 'reason_code' => $d->reason_code, 'at' => $d->created_at];
        }

        return $out;
    }

    /** Outstanding (approved, not yet paid) by aging bucket of the submission date. */
    public function aging(User $user, ProviderScope $s, array $f): array
    {
        $out = array_fill_keys(ProviderWorkspaceRegister::AGING_BUCKETS, 0);
        foreach ($this->filteredClaims($user, $s, $f)->whereIn('c.status', self::OUTSTANDING)->get(['c.submitted_at', 'c.insurer_share_minor']) as $c) {
            $out[self::bucket($c->submitted_at)] += (int) $c->insurer_share_minor;
        }

        return $out;
    }

    public static function bucket(?string $at): string
    {
        $days = $at ? (int) CarbonImmutable::parse($at)->startOfDay()->diffInDays(CarbonImmutable::today()) : 0;

        return match (true) {
            $days <= 0 => 'CURRENT', $days <= 30 => 'DAYS_1_30', $days <= 60 => 'DAYS_31_60', $days <= 90 => 'DAYS_61_90', $days <= 120 => 'DAYS_91_120', default => 'DAYS_120_PLUS',
        };
    }

    private function agingWhere(Builder $q, string $bucket): Builder
    {
        [$min, $max] = ['CURRENT' => [null, 0], 'DAYS_1_30' => [1, 30], 'DAYS_31_60' => [31, 60], 'DAYS_61_90' => [61, 90], 'DAYS_91_120' => [91, 120], 'DAYS_120_PLUS' => [121, null]][$bucket]
            ?? throw new ApiProblemException('AGING_BUCKET_UNKNOWN', 422, "Unknown aging bucket {$bucket}.");
        $today = CarbonImmutable::today();

        return $q->when($max !== null, fn ($w) => $w->where('c.submitted_at', '>=', $today->subDays($max)->startOfDay()))
            ->when($min !== null, fn ($w) => $w->where('c.submitted_at', '<', $today->subDays($min - 1)->startOfDay()));
    }

    // ----------------------------------------------------------------- settlements

    public function settlements(User $user, ProviderScope $s, array $f): array
    {
        return DB::table('health_provider_settlement_batches')->whereIn('provider_profile_id', $this->access->organisation($s->providerId))
            ->when($f['insurer_id'] ?? null, fn ($q, $v) => $q->where('tenant_id', $v))
            ->when($f['settlement_status'] ?? $f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')->paginate(min((int) ($f['per_page'] ?? 50), 200))->toArray();
    }

    public function settlement(User $user, ProviderScope $s, string $id): array
    {
        $b = DB::table('health_provider_settlement_batches')->whereIn('provider_profile_id', $this->access->organisation($s->providerId))->where('id', $id)->first()
            ?? throw new ApiProblemException('SETTLEMENT_NOT_FOUND', 404, 'Settlement not found.');
        $claims = $this->claimQuery($user, $s)->where('c.settlement_batch_id', $id)->orderBy('c.claim_number')
            ->get(['c.id', 'c.claim_number', 'c.invoice_reference', 'c.billed_minor', 'c.rejected_minor', 'c.member_share_minor', 'c.insurer_share_minor', 'c.status']);

        // DOC-198 statement body: every figure derives from the claim records of the batch.
        return ['settlement' => (array) $b, 'lines' => $claims->all(), 'statement' => [
            'approved_claims' => (int) $claims->sum('insurer_share_minor'), 'member_shares' => (int) $claims->sum('member_share_minor'),
            'deductions' => (int) $claims->sum('rejected_minor'), 'payments' => $b->status === 'PAID' ? (int) $b->total_minor : 0, 'currency' => $b->currency,
            'payment_reference' => $b->payment_reference, 'payment_date' => $b->paid_at, 'document_type' => 'DOC-198',
        ]];
    }

    // ----------------------------------------------------------------- dashboard, reports, audit

    public function dashboard(string $tenantId, User $user, ProviderScope $s): array
    {
        $claims = $this->claimQuery($user, $s)->groupBy('c.status')->selectRaw('c.status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($v) => (int) $v)->all();
        $pre = $this->preauthQuery($tenantId, $user, $s)->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($v) => (int) $v)->all();
        $accounts = $this->accounts($user, $s);
        $sum = fn (string $k) => array_sum(array_column($accounts, $k));

        return [
            'provider_executive' => ['submitted_claims' => array_sum($claims) - ($claims['DRAFT'] ?? 0), 'approved_claims' => ($claims['APPROVED'] ?? 0) + ($claims['PARTIALLY_APPROVED'] ?? 0) + ($claims['PAYABLE'] ?? 0) + ($claims['PAID'] ?? 0),
                'pending_claims' => ($claims['SUBMITTED'] ?? 0) + ($claims['UNDER_REVIEW'] ?? 0), 'rejected_claims' => $claims['REJECTED'] ?? 0, 'queried_claims' => 0,
                'payable_amount' => $sum('payable_amount'), 'paid_amount' => $sum('paid_amount'), 'outstanding_amount' => $sum('outstanding_amount'), 'disputed_amount' => $sum('disputed_amount'),
                'patient_share_amount' => $sum('patient_share_amount'), 'aging' => $this->aging($user, $s, [])],
            'insurance_desk' => ['eligibility_checks_today' => DB::table('health_eligibility_checks')->where('provider_profile_id', $s->providerId)->where('checked_at', '>=', CarbonImmutable::today())->count(),
                'preauth_pending' => ($pre['REQUESTED'] ?? 0) + ($pre['PENDING_APPROVAL'] ?? 0) + ($pre['REFERRED'] ?? 0), 'preauth_queries' => $pre['INFO_REQUESTED'] ?? 0,
                'approved_preauth' => ($pre['APPROVED'] ?? 0) + ($pre['PARTIALLY_APPROVED'] ?? 0), 'rejected_preauth' => $pre['DECLINED'] ?? 0,
                'admissions_pending' => $this->preauthQuery($tenantId, $user, $s)->where('request_type', 'ADMISSION')->whereIn('status', ['APPROVED', 'PARTIALLY_APPROVED'])->count(),
                'extensions_pending' => DB::table('health_preauthorization_extensions')->whereIn('health_preauthorization_id', $this->preauthQuery($tenantId, $user, $s)->select('id'))->whereNotIn('status', ['APPROVED', 'PARTIALLY_APPROVED', 'DECLINED'])->count()],
            'claims_billing' => ['claims_ready_to_submit' => $claims['DRAFT'] ?? 0, 'claims_submitted' => $claims['SUBMITTED'] ?? 0, 'claims_partially_approved' => $claims['PARTIALLY_APPROVED'] ?? 0,
                'claims_approved' => $claims['APPROVED'] ?? 0, 'invoices_unsettled' => ($claims['PAYABLE'] ?? 0) + ($claims['APPROVED'] ?? 0) + ($claims['PARTIALLY_APPROVED'] ?? 0),
                'episodes_open' => DB::table('treatment_episodes')->whereIn('provider_profile_id', $this->access->organisation($s->providerId))->where('status', 'OPEN')->count()],
            'finance' => ['by_insurer' => $accounts, 'unreconciled_payments' => DB::table('provider_reconciliations')->whereIn('provider_profile_id', $this->access->organisation($s->providerId))->where('status', '<>', 'MATCHED')->count()],
        ];
    }

    /** Report rows for the active filters (the export uses exactly the same rows). */
    public function report(User $user, ProviderScope $s, string $report, array $f): array
    {
        if (! in_array($report, ProviderWorkspaceRegister::REPORTS, true)) {
            throw new ApiProblemException('REPORT_UNKNOWN', 404, 'Unknown report.');
        }
        $claims = fn () => $this->filteredClaims($user, $s, $f);
        $by = fn (string $col, string $alias) => $claims()->where('c.status', '<>', 'DRAFT')->groupBy($col)->selectRaw("{$col} as {$alias}, COUNT(*) as claims, SUM(c.billed_minor) as billed_minor, SUM(c.insurer_share_minor) as insurer_share_minor, SUM(c.rejected_minor) as rejected_minor")
            ->orderBy($alias)->get()->map(fn ($r) => array_map(fn ($v) => is_numeric($v) && ! str_contains((string) $v, '-') ? (int) $v : $v, (array) $r))->all();
        $rate = function (array $ok) use ($claims) {
            $all = $claims()->whereIn('c.status', ['APPROVED', 'PARTIALLY_APPROVED', 'REJECTED', 'PAYABLE', 'PAID'])->count();

            return [['adjudicated' => $all, 'matching' => $n = $claims()->whereIn('c.status', $ok)->count(), 'rate' => $all ? round($n / $all, 4) : null]];
        };

        return match ($report) {
            'PROVIDER_RECEIVABLES_BY_INSURER', 'PROVIDER_ACTIVITY_BY_INSURER' => $this->accounts($user, $s, $f),
            'PROVIDER_RECEIVABLE_AGING' => [$this->aging($user, $s, $f)],
            'CLAIMS_BY_STATUS' => $by('c.status', 'status'),
            'CLAIMS_BY_INSURER' => $by('c.tenant_id', 'insurer_id'),
            'CLAIMS_BY_FACILITY' => $by('c.provider_facility_id', 'facility_id'),
            'CLAIMS_BY_PATIENT' => $by('c.member_reference', 'member_reference'),
            'CLAIMS_BY_SERVICE', 'TARIFF_VARIANCE' => DB::table('health_provider_claim_lines as l')->join('medical_services as m', 'm.id', '=', 'l.medical_service_id')
                ->whereIn('l.health_provider_claim_id', $claims()->select('c.id'))->groupBy('m.code')
                ->selectRaw('m.code as service_code, COUNT(*) as lines, SUM(l.billed_minor) as billed_minor, SUM(l.allowed_minor) as allowed_minor, SUM(l.billed_minor - l.allowed_minor) as variance_minor')
                ->orderBy('m.code')->get()->map(fn ($r) => (array) $r)->all(),
            'CLAIM_APPROVAL_RATE' => $rate(['APPROVED', 'PARTIALLY_APPROVED', 'PAYABLE', 'PAID']),
            'CLAIM_REJECTION_RATE' => $rate(['REJECTED']),
            'DEDUCTION_ANALYSIS' => collect($this->entries($user, $s, $f))->where('type', 'DEDUCTIONS')->groupBy('reason_code')
                ->map(fn ($g, $k) => ['reason_code' => $k, 'lines' => $g->count(), 'amount_minor' => $g->sum('amount')])->values()->all(),
            'DISPUTE_ANALYSIS' => DB::table('provider_disputes')->whereIn('provider_profile_id', $this->access->organisation($s->providerId))
                ->when($f['dispute_status'] ?? null, fn ($q, $v) => $q->where('status', $v))->groupBy('reason_code', 'status')
                ->selectRaw('reason_code, status, COUNT(*) as disputes, SUM(disputed_amount_minor) as disputed_minor')->orderBy('reason_code')->get()->map(fn ($r) => (array) $r)->all(),
            'SETTLEMENT_PERFORMANCE' => [['average_settlement_days' => collect($this->accounts($user, $s, $f))->avg('average_settlement_days'),
                'paid_batches' => DB::table('health_provider_settlement_batches')->whereIn('provider_profile_id', $this->access->organisation($s->providerId))->where('status', 'PAID')->count()]],
            'UNRECONCILED_PAYMENTS', 'BULK_PAYMENT_ALLOCATION_REPORT' => DB::table('provider_reconciliations')->whereIn('provider_profile_id', $this->access->organisation($s->providerId))
                ->when($report === 'UNRECONCILED_PAYMENTS', fn ($q) => $q->where('status', '<>', 'MATCHED'))
                ->when($f['reconciliation_status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderBy('received_on')->get()->map(fn ($r) => (array) $r)->all(),
            'PATIENT_SHARE_REGISTER' => $claims()->where('c.member_share_minor', '>', 0)->orderBy('c.service_date')
                ->get(['c.claim_number', 'c.invoice_reference', 'c.member_reference', 'c.service_date', 'c.member_share_minor', 'c.currency'])->map(fn ($r) => (array) $r)->all(),
            'PREAUTH_TURNAROUND', 'PREAUTH_APPROVAL_RATE' => $this->preauthStats($user, $s, $f, $report),
        };
    }

    private function preauthStats(User $user, ProviderScope $s, array $f, string $report): array
    {
        $tenant = $f['insurer_id'] ?? app(\App\Domain\Tenancy\TenantContext::class)->id();
        $q = $this->preauthQuery($tenant, $user, $s)->whereNotNull('decided_at');
        if ($report === 'PREAUTH_TURNAROUND') {
            return [['decided' => (clone $q)->count(), 'average_hours' => ($h = (clone $q)->selectRaw('AVG(EXTRACT(EPOCH FROM (decided_at - created_at)) / 3600) as h')->value('h')) === null ? null : round((float) $h, 2)]];
        }
        $all = (clone $q)->count();
        $ok = (clone $q)->whereIn('status', ['APPROVED', 'PARTIALLY_APPROVED', 'ADMITTED', 'DISCHARGED'])->count();

        return [['decided' => $all, 'approved' => $ok, 'rate' => $all ? round($ok / $all, 4) : null]];
    }

    /** CSV of exactly the report rows for the active filters. */
    public function csv(array $rows): string
    {
        $flat = array_map(fn ($r) => array_map(fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v, (array) $r), $rows);
        $cols = [];
        foreach ($flat as $r) {
            $cols = array_values(array_unique([...$cols, ...array_keys($r)]));
        }
        $h = fopen('php://temp', 'r+');
        fputcsv($h, $cols);
        foreach ($flat as $r) {
            fputcsv($h, array_map(fn ($c) => $r[$c] ?? '', $cols));
        }
        rewind($h);

        return (string) stream_get_contents($h);
    }

    /** Audit trail of the provider's own records (eligibility, preauth, claim, settlement, dispute, reconciliation, users). */
    public function auditLog(User $user, ProviderScope $s, array $f): array
    {
        $org = $this->access->organisation($s->providerId);
        $subjects = DB::table('health_provider_claims')->whereIn('provider_profile_id', $org)->pluck('id')
            ->merge(DB::table('health_preauthorizations')->whereIn('provider_profile_id', $org)->pluck('id'))
            ->merge(DB::table('treatment_episodes')->whereIn('provider_profile_id', $org)->pluck('id'))
            ->merge(DB::table('provider_disputes')->whereIn('provider_profile_id', $org)->pluck('id'))
            ->merge(DB::table('provider_reconciliations')->whereIn('provider_profile_id', $org)->pluck('id'))
            ->merge(DB::table('health_provider_settlement_batches')->whereIn('provider_profile_id', $org)->pluck('id'))
            ->merge(DB::table('provider_users')->whereIn('provider_profile_id', $org)->pluck('id'))->merge($org)->map(fn ($v) => (string) $v)->all();

        return DB::table('audit_log')->where(fn ($q) => $q->whereIn('subject_id', $subjects)->orWhereIn(DB::raw("metadata->>'provider_id'"), $org))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', CarbonImmutable::parse($v)->endOfDay()))
            ->orderByDesc('sequence')->limit(500)->get(['id', 'action', 'subject_type', 'subject_id', 'actor_id', 'reason_code', 'source', 'created_at'])->all();
    }

    // ----------------------------------------------------------------- contracts / tariffs detail

    public function contract(string $tenantId, ProviderScope $s, string $id): array
    {
        $c = DB::table('provider_contracts')->where(['tenant_id' => $tenantId, 'id' => $id])->whereIn('provider_profile_id', $this->access->organisation($s->providerId))
            ->where('status', '<>', 'DRAFT')->first() ?? throw new ApiProblemException('CONTRACT_NOT_FOUND', 404, 'Contract not found.');
        $versions = DB::table('provider_tariff_versions')->where('provider_contract_id', $id)->whereIn('status', ['APPROVED', 'SUPERSEDED'])->orderByDesc('version')->get();

        return ['contract' => (array) $c + ['insurer_id' => $c->tenant_id, 'document_type' => 'DOC-215'], 'tariff_versions' => $versions->all()];
    }

    public function tariff(string $tenantId, ProviderScope $s, string $id): array
    {
        $v = DB::table('provider_tariff_versions as v')->join('provider_contracts as c', 'c.id', '=', 'v.provider_contract_id')->where('c.tenant_id', $tenantId)
            ->whereIn('c.provider_profile_id', $this->access->organisation($s->providerId))->where('v.id', $id)->whereIn('v.status', ['APPROVED', 'SUPERSEDED'])
            ->select('v.*', 'c.contract_number')->first() ?? throw new ApiProblemException('TARIFF_NOT_FOUND', 404, 'Tariff not found.');
        $lines = DB::table('provider_tariff_lines as l')->join('medical_services as m', 'm.id', '=', 'l.medical_service_id')->where('l.provider_tariff_version_id', $id)
            ->orderBy('m.code')->select('l.*', 'm.code as service_code', 'm.name as service_name', 'm.name_fr as service_name_fr')->get()->all();

        return ['tariff' => (array) $v + ['document_type' => 'DOC-216'], 'lines' => $lines];
    }

    // ----------------------------------------------------------------- helpers

    /** @param array<string, mixed> $row */
    public function redact(User $user, ProviderScope $s, array $row): array
    {
        if (! $this->access->mayReadClinical($user, $s)) {
            foreach (self::CLINICAL_FIELDS as $k) {
                if (array_key_exists($k, $row)) {
                    $row[$k] = $k === 'type_details' && is_array($row[$k]) ? array_diff_key($row[$k], array_flip(['diagnosis_code', 'admission_reason'])) : null;
                }
            }
            if (isset($row['type_details']) && is_string($row['type_details'])) {
                $row['type_details'] = array_diff_key(json_decode($row['type_details'], true) ?: [], array_flip(['diagnosis_code', 'admission_reason']));
            }
            $row['clinical_redacted'] = true;
        }

        return $row;
    }

    private function uuidOrNull(string $v): ?string
    {
        return preg_match('/^[0-9a-f-]{36}$/i', $v) ? $v : null;
    }
}
