<?php

declare(strict_types=1);

namespace App\Application\Health\Preauth;

use App\Application\Audit\AuditWriter;
use App\Application\Authority\AuthorityService;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Events\OutboxWriter;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Policy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-HLT-002 — health preauthorization and guarantee of payment (GOP).
 *
 *  - request: a provider (or staff on its behalf) submits a typed request (ADMISSION / OUTPATIENT / PHARMACY / LAB with
 *    its own fields) with lines coded from the Batch 13A medical service catalogue or the provider's own codes
 *    (provider code mapping); each line is priced from the approved contract tariff (ProviderNetworkService::priceFor).
 *    Eligibility is checked per line by the health EligibilityService when deployed, else from the policy in force.
 *  - review (maker): request info (INFO_REQUESTED), or propose APPROVED / PARTIAL / DECLINED with the GOP validity
 *    window. The maker's preauthorization authority (health_preauth.authority_type) is checked; over limit → REFERRED (AUTHORITY_REFERRAL case).
 *  - decide (checker ≠ maker ≠ requester): the checker's own limit must cover the amount (a REFERRED proposal needs
 *    health.preauth.supervise). Approval reserves the benefit (BenefitAccumulator when deployed) and fires the
 *    GOP documents through the document engine with the validity window.
 *  - ADMISSION: admit, stay extensions (same maker-checker, own case), discharge.
 *  - SLA: HEALTH_PREAUTHORIZATION case per request / extension (case_subtype = request type); no targets seeded.
 */
final class PreauthorizationService
{
    /** Batch 14 E2 / E5 services, called only when deployed (Wave A contract). */
    private const ELIGIBILITY = 'App\\Application\\Health\\Eligibility\\EligibilityService';

    private const ACCUMULATOR = 'App\\Application\\Health\\Benefits\\BenefitAccumulator';

    private const IN_FORCE = ['ACTIVE', 'EXPIRING', 'ENDORSEMENT_PENDING', 'CANCELLATION_PENDING'];

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ProviderNetworkService $networks,
        private readonly CaseService $cases,
        private readonly AuthorityService $authority,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    // ----------------------------------------------------------------- request

    /**
     * @param array{request_type: string, policy_id: string, member_ref?: ?string, provider_id: string, facility_id?: ?string, contract_id?: ?string,
     *   details: array<string, mixed>, clinical_notes?: ?string,
     *   lines: list<array{service_code?: ?string, provider_code?: ?string, quantity: int|float|string, unit_price_minor?: ?int}>} $d
     */
    public function request(string $tenantId, array $d, User $actor): object
    {
        $type = $d['request_type'];
        $policy = Policy::where('tenant_id', $tenantId)->whereKey($d['policy_id'])->first()
            ?? throw new ApiProblemException('POLICY_NOT_FOUND', 404, 'Policy not found.');
        $p = $this->providers->find($d['provider_id']);
        if ($p->category !== 'HEALTH') {
            throw new ApiProblemException('PROVIDER_NOT_HEALTH', 422, "A {$p->category} provider cannot request a health preauthorization.");
        }
        if ($p->credentialing_status !== 'ACTIVE') {
            throw new ApiProblemException('PROVIDER_NOT_ACTIVE', 409, "Only ACTIVE providers can request a preauthorization (provider is {$p->credentialing_status}).");
        }
        $facilityId = $d['facility_id'] ?? null;
        if ($facilityId && ! DB::table('provider_facilities')->where('id', $facilityId)->where('provider_profile_id', $p->id)->exists()) {
            throw new ApiProblemException('FACILITY_NOT_FOUND', 422, 'The facility does not belong to this provider.');
        }
        $details = $d['details'];
        $serviceDate = CarbonImmutable::parse($details[PreauthLifecycle::SERVICE_DATE_FIELD[$type]])->toDateString();
        $contract = $this->contractFor($tenantId, $p->id, $d['contract_id'] ?? null, $serviceDate);
        $memberRef = $d['member_ref'] ?? $policy->party_id;

        $lines = [];
        $input = $d['lines'];
        ksort($input); // validated arrays can come back out of index order
        foreach (array_values($input) as $i => $l) {
            $lines[] = $this->priceLine($tenantId, $p->id, $contract, $l, $i + 1, $serviceDate);
        }
        $currencies = array_values(array_unique(array_filter(array_column($lines, 'currency'))));
        if (count($currencies) > 1) {
            throw new ApiProblemException('CURRENCY_MISMATCH', 422, 'All lines of a preauthorization must be priced in one currency.');
        }
        $currency = $currencies[0] ?? (string) $policy->currency;
        foreach ($lines as &$l) {
            $l['eligibility'] = $this->eligibility($tenantId, $policy, $memberRef, $p->id, $l['service_code'], $serviceDate);
        }
        unset($l);
        $eligible = $lines !== [] && ! in_array(false, array_map(fn ($l) => (bool) ($l['eligibility']['eligible'] ?? false), $lines), true);

        return DB::transaction(function () use ($tenantId, $type, $policy, $p, $facilityId, $contract, $details, $serviceDate, $memberRef, $lines, $currency, $eligible, $d, $actor) {
            $id = (string) Str::uuid();
            $number = 'PA-'.now()->format('Ym').'-'.strtoupper(Str::random(8));
            $case = $this->cases->open($tenantId, PreauthLifecycle::CASE_TYPE, [
                'title' => "Preauthorization {$number} — {$type} — {$p->name}", 'subject_type' => 'health_preauthorization', 'subject_id' => $id,
                'source_type' => 'health_preauthorization', 'source_id' => $id, 'carrier_id' => $policy->carrier_id, 'case_subtype' => $type,
                'priority' => ! empty($details['emergency']) ? 'HIGH' : 'NORMAL', 'idempotency_key' => 'health-preauth-'.$id,
            ], $actor);
            DB::table('health_preauthorizations')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'carrier_id' => $policy->carrier_id, 'preauth_number' => $number, 'request_type' => $type,
                'status' => 'REQUESTED', 'policy_id' => $policy->id, 'member_ref' => $memberRef, 'provider_profile_id' => $p->id,
                'provider_facility_id' => $facilityId, 'provider_contract_id' => $contract?->id, 'service_date' => $serviceDate,
                'type_details' => json_encode($details, JSON_THROW_ON_ERROR), 'clinical_notes' => $d['clinical_notes'] ?? null,
                'eligibility' => json_encode(['eligible' => $eligible, 'source' => $lines[0]['eligibility']['source'] ?? null], JSON_THROW_ON_ERROR), 'eligible' => $eligible,
                'currency' => $currency, 'requested_amount_minor' => array_sum(array_column($lines, 'requested_amount_minor')),
                'insurer_amount_minor' => array_sum(array_column($lines, 'insurer_amount_minor')),
                'approved_until' => $type === 'ADMISSION' ? CarbonImmutable::parse($details['expected_discharge_date'])->toDateString() : null,
                'case_id' => $case->id, 'requested_by' => $actor->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->insertLines($id, null, $lines);
            $this->event($id, null, 'request', null, 'REQUESTED', $actor, null, ['eligible' => $eligible, 'lines' => count($lines)]);
            $this->publish('request', $id, ['request_type' => $type, 'provider_id' => $p->id, 'policy_id' => $policy->id, 'eligible' => $eligible, 'status' => 'REQUESTED']);

            return $this->find($tenantId, $id);
        });
    }

    public function requestInfo(string $tenantId, string $id, string $question, User $actor): object
    {
        return $this->move($tenantId, $id, 'request_info', $actor, $question, fn () => ['decision' => 'INFO_REQUESTED', 'info_request' => $question]);
    }

    public function provideInfo(string $tenantId, string $id, string $answer, User $actor): object
    {
        return $this->move($tenantId, $id, 'provide_info', $actor, null, fn ($pa) => [
            'decision' => null, 'clinical_notes' => trim(($pa->clinical_notes ? $pa->clinical_notes."\n\n" : '').'[info] '.$answer),
        ], ['answer' => $answer]);
    }

    // ----------------------------------------------------------------- maker

    /**
     * @param array{decision: string, reason_code?: ?string, notes?: ?string, valid_from?: ?string, valid_until?: ?string,
     *   lines?: list<array{line_id: string, approved_quantity?: int|float|string|null, approved_amount_minor?: ?int, decline_reason?: ?string}>} $d
     */
    public function propose(string $tenantId, string $id, array $d, User $actor): object
    {
        return DB::transaction(function () use ($tenantId, $id, $d, $actor) {
            $pa = $this->lock($tenantId, $id);
            $this->assertNotRequester($pa, $actor);
            $decision = $d['decision'];
            if ($decision !== 'DECLINED' && ! $pa->eligible) {
                throw new ApiProblemException('PREAUTH_NOT_ELIGIBLE', 409, 'The member is not eligible for this request: only a decline can be proposed.');
            }
            $lines = DB::table('health_preauthorization_lines')->where('health_preauthorization_id', $id)->whereNull('extension_id')->orderBy('line_no')->get();
            $decided = $this->decideLines($lines, $decision, $d['lines'] ?? []);
            $amount = array_sum(array_column($decided, 'approved_amount_minor'));
            [$from, $until] = $decision === 'DECLINED' ? [null, null] : $this->validity($pa->service_date, $d['valid_from'] ?? null, $d['valid_until'] ?? null);
            if ($decision === 'DECLINED' && trim((string) ($d['reason_code'] ?? '')) === '') {
                throw new ApiProblemException('REASON_REQUIRED', 422, 'A decline needs a reason_code.');
            }
            $check = $amount > 0 ? $this->authority->checkStaffLimit($tenantId, (string) $pa->carrier_id, $actor, PreauthLifecycle::authorityType(), $amount, $pa->currency,
                ['type' => 'health_preauthorization', 'id' => $id, 'title' => 'Preauthorization '.$pa->preauth_number], 'PREAUTH_DECISION') : null;
            $event = $check?->referred() ? 'refer' : 'propose';
            foreach ($decided as $lineId => $l) {
                DB::table('health_preauthorization_lines')->where('id', $lineId)->update($l + ['updated_at' => now()]);
            }

            return $this->transition($pa, $event, $actor, null, [
                'proposed_decision' => $decision, 'approved_amount_minor' => $amount, 'decision_reason_code' => $d['reason_code'] ?? null,
                'decision_notes' => $d['notes'] ?? null, 'gop_valid_from' => $from, 'gop_valid_until' => $until, 'proposed_by' => $actor->id,
                'maker_authority_check_id' => $check?->checkId, 'referral_case_id' => $check?->referralCaseId,
            ], ['decision' => $decision, 'amount_minor' => $amount, 'authority' => $check?->outcome]);
        });
    }

    public function returnProposal(string $tenantId, string $id, string $reason, User $actor): object
    {
        return DB::transaction(function () use ($tenantId, $id, $reason, $actor) {
            $pa = $this->lock($tenantId, $id);
            $this->assertChecker($pa, $actor);
            DB::table('health_preauthorization_lines')->where('health_preauthorization_id', $id)->whereNull('extension_id')
                ->update(['line_decision' => null, 'approved_quantity' => null, 'approved_amount_minor' => null, 'decline_reason' => null, 'updated_at' => now()]);

            return $this->transition($pa, 'return', $actor, $reason, ['proposed_decision' => null, 'approved_amount_minor' => null, 'gop_valid_from' => null, 'gop_valid_until' => null]);
        });
    }

    // ----------------------------------------------------------------- checker

    public function decide(string $tenantId, string $id, User $actor): object
    {
        $pa = DB::transaction(function () use ($tenantId, $id, $actor) {
            $pa = $this->lock($tenantId, $id);
            $this->assertChecker($pa, $actor);
            $decision = (string) $pa->proposed_decision;
            $amount = (int) $pa->approved_amount_minor;
            $check = $this->checkerAuthority($pa, $amount, $actor, $id, 'PREAUTH_DECISION_APPROVE', $pa->status === 'REFERRED');
            if ($check?->referred()) {
                return $this->transition($pa, 'refer', $actor, null, ['referral_case_id' => $check->referralCaseId, 'checker_authority_check_id' => $check->checkId],
                    ['amount_minor' => $amount, 'authority' => $check->reason, 'by' => 'checker']);
            }
            $reservations = $decision === 'DECLINED' ? [] : $this->reserve($pa, DB::table('health_preauthorization_lines')->where('health_preauthorization_id', $id)
                ->whereNull('extension_id')->where('approved_amount_minor', '>', 0)->get()->all(), $id);

            return $this->transition($pa, PreauthLifecycle::DECISION_EVENT[$decision], $actor, null, [
                'decision' => $decision, 'decided_by' => $actor->id, 'decided_at' => now(), 'checker_authority_check_id' => $check?->checkId,
                'benefit_reservations' => json_encode($reservations, JSON_THROW_ON_ERROR),
            ], ['decision' => $decision, 'amount_minor' => $amount, 'reservations' => count($reservations)]);
        });
        if ($pa->status === 'REFERRED') {
            return $pa;
        }
        $trigger = ['APPROVED' => 'PREAUTH_APPROVED', 'PARTIALLY_APPROVED' => 'PREAUTH_PARTIALLY_APPROVED', 'DECLINED' => 'PREAUTH_DECLINED'][$pa->status];
        $manifest = $this->fireDocuments($trigger, $pa, null, $actor, $pa->request_type === 'ADMISSION' && $pa->status !== 'DECLINED' ? ['HOSPITAL_ADMISSION_AUTHORIZATION'] : []);
        if ($manifest) {
            DB::table('health_preauthorizations')->where('id', $pa->id)->update(['gop_manifest_id' => $manifest->id]);
        }

        return $this->find($tenantId, $pa->id);
    }

    // ----------------------------------------------------------------- admission

    public function admit(string $tenantId, string $id, ?string $admittedOn, User $actor): object
    {
        return DB::transaction(function () use ($tenantId, $id, $admittedOn, $actor) {
            $pa = $this->lock($tenantId, $id);
            $this->assertAdmission($pa);
            $on = CarbonImmutable::parse($admittedOn ?? now())->toDateString();
            if ($pa->gop_valid_until && ($on < $pa->gop_valid_from || $on > $pa->gop_valid_until)) {
                throw new ApiProblemException('GOP_NOT_VALID', 409, 'The admission date is outside the guarantee of payment validity window.');
            }

            return $this->transition($pa, 'admit', $actor, null, ['admitted_on' => $on], ['admitted_on' => $on]);
        });
    }

    public function discharge(string $tenantId, string $id, ?string $dischargedOn, User $actor): object
    {
        return DB::transaction(function () use ($tenantId, $id, $dischargedOn, $actor) {
            $pa = $this->lock($tenantId, $id);
            $this->assertAdmission($pa);
            $on = CarbonImmutable::parse($dischargedOn ?? now())->toDateString();
            if ($on < $pa->admitted_on) {
                throw new ApiProblemException('DISCHARGE_BEFORE_ADMISSION', 422, 'Discharge cannot precede admission.');
            }
            if (DB::table('health_preauthorization_extensions')->where('health_preauthorization_id', $id)->whereIn('status', ['REQUESTED', 'PENDING_APPROVAL', 'REFERRED'])->exists()) {
                throw new ApiProblemException('EXTENSION_PENDING', 409, 'Decide or cancel the pending stay extension before discharge.');
            }

            return $this->transition($pa, 'discharge', $actor, null, ['discharged_on' => $on],
                ['discharged_on' => $on, 'beyond_approved_stay' => $pa->approved_until !== null && $on > $pa->approved_until]);
        });
    }

    /** @param array{requested_until: string, reason: string, lines?: list<array<string, mixed>>} $d */
    public function requestExtension(string $tenantId, string $id, array $d, User $actor): object
    {
        $pa = $this->find($tenantId, $id);
        $this->assertAdmission($pa);
        if ($pa->status !== 'ADMITTED') {
            throw new ApiProblemException('PREAUTH_NOT_ADMITTED', 409, 'A stay extension needs an admitted patient.');
        }
        $until = CarbonImmutable::parse($d['requested_until'])->toDateString();
        if ($until <= (string) $pa->approved_until) {
            throw new ApiProblemException('EXTENSION_NOT_LATER', 422, 'The requested stay end must be after the currently approved stay end.');
        }
        $contract = $pa->provider_contract_id ? $this->networks->contract($tenantId, $pa->provider_contract_id) : null;
        $lines = [];
        $next = (int) DB::table('health_preauthorization_lines')->where('health_preauthorization_id', $id)->max('line_no');
        $input = $d['lines'] ?? [];
        ksort($input);
        foreach (array_values($input) as $i => $l) {
            $priced = $this->priceLine($tenantId, $pa->provider_profile_id, $contract, $l, $next + $i + 1, (string) $pa->admitted_on);
            if ($priced['currency'] && $priced['currency'] !== $pa->currency) {
                throw new ApiProblemException('CURRENCY_MISMATCH', 422, 'Extension lines must be priced in the preauthorization currency.');
            }
            $priced['eligibility'] = $this->eligibility($tenantId, Policy::findOrFail($pa->policy_id), $pa->member_ref, $pa->provider_profile_id, $priced['service_code'], (string) $pa->admitted_on);
            $lines[] = $priced;
        }

        return DB::transaction(function () use ($tenantId, $pa, $d, $until, $lines, $actor) {
            $this->lock($tenantId, $pa->id);
            if (DB::table('health_preauthorization_extensions')->where('health_preauthorization_id', $pa->id)->whereIn('status', ['REQUESTED', 'PENDING_APPROVAL', 'REFERRED'])->exists()) {
                throw new ApiProblemException('EXTENSION_PENDING', 409, 'A stay extension is already pending.');
            }
            $extId = (string) Str::uuid();
            $seq = (int) DB::table('health_preauthorization_extensions')->where('health_preauthorization_id', $pa->id)->max('sequence') + 1;
            $case = $this->cases->open($tenantId, PreauthLifecycle::CASE_TYPE, [
                'title' => "Stay extension {$seq} — {$pa->preauth_number}", 'subject_type' => 'health_preauthorization', 'subject_id' => $pa->id,
                'source_type' => 'health_preauthorization_extension', 'source_id' => $extId, 'carrier_id' => $pa->carrier_id, 'case_subtype' => 'EXTENSION',
                'parent_case_id' => $pa->case_id, 'idempotency_key' => 'health-preauth-ext-'.$extId,
            ], $actor);
            DB::table('health_preauthorization_extensions')->insert([
                'id' => $extId, 'health_preauthorization_id' => $pa->id, 'sequence' => $seq, 'status' => 'REQUESTED', 'requested_until' => $until,
                'requested_amount_minor' => array_sum(array_column($lines, 'insurer_amount_minor')), 'reason' => $d['reason'], 'case_id' => $case->id,
                'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->insertLines($pa->id, $extId, $lines);
            $this->event($pa->id, $extId, 'extension_requested', $pa->status, $pa->status, $actor, $d['reason'], ['requested_until' => $until]);
            $this->publish('extension_requested', $pa->id, ['extension_id' => $extId, 'requested_until' => $until]);

            return $this->extension($pa->id, $extId);
        });
    }

    /** @param array{decision: string, approved_until?: ?string, reason_code?: ?string, lines?: list<array<string, mixed>>} $d */
    public function proposeExtension(string $tenantId, string $id, string $extId, array $d, User $actor): object
    {
        return DB::transaction(function () use ($tenantId, $id, $extId, $d, $actor) {
            $pa = $this->lock($tenantId, $id);
            $ext = $this->lockExtension($pa->id, $extId, ['REQUESTED']);
            if ($ext->requested_by === $actor->id) {
                throw new ApiProblemException('MAKER_CHECKER', 403, 'The requester cannot review their own extension.');
            }
            $decision = $d['decision'];
            $lines = DB::table('health_preauthorization_lines')->where('extension_id', $extId)->orderBy('line_no')->get();
            $decided = $this->decideLines($lines, $decision, $d['lines'] ?? []);
            $amount = array_sum(array_column($decided, 'approved_amount_minor'));
            $until = null;
            if ($decision === 'DECLINED') {
                if (trim((string) ($d['reason_code'] ?? '')) === '') {
                    throw new ApiProblemException('REASON_REQUIRED', 422, 'A decline needs a reason_code.');
                }
            } else {
                $until = CarbonImmutable::parse($d['approved_until'] ?? $ext->requested_until)->toDateString();
                if ($until <= (string) $pa->approved_until || $until > (string) $ext->requested_until) {
                    throw new ApiProblemException('EXTENSION_DATES_INVALID', 422, 'approved_until must be after the current approved stay end and not beyond the requested one.');
                }
            }
            $check = $amount > 0 ? $this->authority->checkStaffLimit($tenantId, (string) $pa->carrier_id, $actor, PreauthLifecycle::authorityType(), $amount, $pa->currency,
                ['type' => 'health_preauthorization_extension', 'id' => $extId, 'title' => 'Stay extension '.$pa->preauth_number], 'PREAUTH_EXTENSION') : null;
            foreach ($decided as $lineId => $l) {
                DB::table('health_preauthorization_lines')->where('id', $lineId)->update($l + ['updated_at' => now()]);
            }
            $to = $check?->referred() ? 'REFERRED' : 'PENDING_APPROVAL';
            DB::table('health_preauthorization_extensions')->where('id', $extId)->update([
                'status' => $to, 'proposed_decision' => $decision, 'approved_until' => $until, 'approved_amount_minor' => $amount,
                'decision_reason_code' => $d['reason_code'] ?? null, 'maker_authority_check_id' => $check?->checkId, 'proposed_by' => $actor->id, 'updated_at' => now(),
            ]);
            $this->caseStep($ext->case_id, 'REQUESTED', $to === 'REFERRED' ? 'refer' : 'propose', $actor, null);
            $this->event($pa->id, $extId, 'extension_'.($to === 'REFERRED' ? 'refer' : 'propose'), $pa->status, $pa->status, $actor, null, ['decision' => $decision, 'amount_minor' => $amount]);

            return $this->extension($pa->id, $extId);
        });
    }

    public function decideExtension(string $tenantId, string $id, string $extId, User $actor): object
    {
        [$pa, $ext] = DB::transaction(function () use ($tenantId, $id, $extId, $actor) {
            $pa = $this->lock($tenantId, $id);
            $ext = $this->lockExtension($pa->id, $extId, ['PENDING_APPROVAL', 'REFERRED']);
            if (in_array($actor->id, [$ext->proposed_by, $ext->requested_by], true)) {
                throw new ApiProblemException('MAKER_CHECKER', 403, 'The maker or requester of an extension cannot decide it.');
            }
            if ($ext->status === 'REFERRED' && ! $actor->hasPermission('health.preauth.supervise')) {
                throw new ApiProblemException('AUTHORITY_SUPERVISOR_REQUIRED', 403, 'A referred extension needs a supervisor decision.');
            }
            $amount = (int) $ext->approved_amount_minor;
            $check = $this->checkerAuthority($pa, $amount, $actor, $extId, 'PREAUTH_EXTENSION_APPROVE', $ext->status === 'REFERRED');
            if ($check?->referred()) {
                DB::table('health_preauthorization_extensions')->where('id', $extId)->update(['status' => 'REFERRED', 'checker_authority_check_id' => $check->checkId, 'updated_at' => now()]);
                $this->caseStep($ext->case_id, $ext->status, 'refer', $actor, null);
                $this->event($pa->id, $extId, 'extension_refer', $pa->status, $pa->status, $actor, null, ['amount_minor' => $amount, 'by' => 'checker']);

                return [$this->find($tenantId, $pa->id), $this->extension($pa->id, $extId)];
            }
            $to = ['APPROVED' => 'APPROVED', 'PARTIAL' => 'PARTIALLY_APPROVED', 'DECLINED' => 'DECLINED'][$ext->proposed_decision];
            $reserved = $to === 'DECLINED' ? [] : $this->reserve($pa, DB::table('health_preauthorization_lines')->where('extension_id', $extId)->where('approved_amount_minor', '>', 0)->get()->all(), $extId);
            DB::table('health_preauthorization_extensions')->where('id', $extId)->update([
                'status' => $to, 'decided_by' => $actor->id, 'decided_at' => now(), 'checker_authority_check_id' => $check?->checkId, 'updated_at' => now(),
            ]);
            if ($to !== 'DECLINED') {
                DB::table('health_preauthorizations')->where('id', $pa->id)->update([
                    'approved_until' => $ext->approved_until,
                    'gop_valid_until' => max((string) $pa->gop_valid_until, (string) $ext->approved_until),
                    'approved_amount_minor' => (int) $pa->approved_amount_minor + $amount,
                    'benefit_reservations' => json_encode([...json_decode((string) $pa->benefit_reservations, true) ?: [], ...$reserved], JSON_THROW_ON_ERROR),
                    'version' => $pa->version + 1, 'updated_at' => now(),
                ]);
            }
            $this->caseStep($ext->case_id, $ext->status, PreauthLifecycle::DECISION_EVENT[$ext->proposed_decision], $actor, null);
            $this->event($pa->id, $extId, 'extension_decided', $pa->status, $pa->status, $actor, null, ['status' => $to, 'amount_minor' => $amount, 'approved_until' => $ext->approved_until]);
            $this->publish('extension_decided', $pa->id, ['extension_id' => $extId, 'status' => $to, 'approved_amount_minor' => $amount, 'approved_until' => $ext->approved_until]);

            return [$this->find($tenantId, $pa->id), $this->extension($pa->id, $extId)];
        });
        if (in_array($ext->status, ['APPROVED', 'PARTIALLY_APPROVED'], true)) {
            $manifest = $this->fireDocuments('PREAUTH_EXTENSION_APPROVED', $pa, $ext, $actor, []);
            if ($manifest) {
                DB::table('health_preauthorization_extensions')->where('id', $extId)->update(['gop_manifest_id' => $manifest->id]);
            }
        }

        return $this->extension($pa->id, $extId);
    }

    public function cancel(string $tenantId, string $id, string $reason, User $actor): object
    {
        return DB::transaction(function () use ($tenantId, $id, $reason, $actor) {
            $pa = $this->lock($tenantId, $id);
            $released = $this->release($pa);

            return $this->transition($pa, 'cancel', $actor, $reason, ['benefit_reservations' => json_encode($released, JSON_THROW_ON_ERROR)], ['released' => count($released)]);
        });
    }

    // ----------------------------------------------------------------- reads

    public function find(string $tenantId, string $id): object
    {
        $pa = DB::table('health_preauthorizations')->where('tenant_id', $tenantId)->where('id', $id)->first()
            ?? throw new ApiProblemException('PREAUTH_NOT_FOUND', 404, 'Preauthorization not found.');

        return $pa;
    }

    /** @return array<string, mixed> */
    public function present(object $pa, bool $full = false): array
    {
        $data = (array) $pa;
        foreach (['type_details', 'eligibility', 'benefit_reservations'] as $k) {
            $data[$k] = is_string($pa->{$k}) ? json_decode($pa->{$k}, true) : $pa->{$k};
        }
        $data['available_events'] = array_values(array_filter(array_keys(PreauthLifecycle::TRANSITIONS), fn ($e) => PreauthLifecycle::target($pa->status, $e) !== null
            && (! in_array($e, ['admit', 'discharge'], true) || $pa->request_type === 'ADMISSION')));
        $data['lines'] = DB::table('health_preauthorization_lines')->where('health_preauthorization_id', $pa->id)->orderBy('line_no')->get()
            ->map(fn ($l) => ['eligibility' => json_decode((string) $l->eligibility, true)] + (array) $l)->all();
        if ($full) {
            $data['extensions'] = DB::table('health_preauthorization_extensions')->where('health_preauthorization_id', $pa->id)->orderBy('sequence')->get()->all();
            $data['history'] = DB::table('health_preauthorization_events')->where('health_preauthorization_id', $pa->id)->orderBy(DB::getDriverName() === 'pgsql' ? 'seq' : 'occurred_at')->get()
                ->map(fn ($e) => ['payload' => json_decode((string) $e->payload, true)] + (array) $e)->all();
            $data['documents'] = $pa->gop_manifest_id ? DB::table('documents')->where('pack_manifest_id', $pa->gop_manifest_id)
                ->get(['id', 'document_type_code', 'document_number', 'status', 'valid_from', 'valid_until'])->all() : [];
        }

        return $data;
    }

    /** @param array{status?: ?string, provider_id?: ?string, policy_id?: ?string, request_type?: ?string} $f */
    public function list(string $tenantId, array $f): array
    {
        return DB::table('health_preauthorizations')->where('tenant_id', $tenantId)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['provider_id'] ?? null, fn ($q, $v) => $q->where('provider_profile_id', $v))
            ->when($f['policy_id'] ?? null, fn ($q, $v) => $q->where('policy_id', $v))
            ->when($f['request_type'] ?? null, fn ($q, $v) => $q->where('request_type', $v))
            ->orderByDesc('created_at')->limit(200)->get()->all();
    }

    public function extension(string $preauthId, string $extId): object
    {
        $e = DB::table('health_preauthorization_extensions')->where('health_preauthorization_id', $preauthId)->where('id', $extId)->first()
            ?? throw new ApiProblemException('EXTENSION_NOT_FOUND', 404, 'Stay extension not found.');
        $e->lines = DB::table('health_preauthorization_lines')->where('extension_id', $extId)->orderBy('line_no')->get()->all();

        return $e;
    }

    // ----------------------------------------------------------------- internals

    private function contractFor(string $tenantId, string $providerId, ?string $contractId, string $day): ?object
    {
        if ($contractId) {
            $c = $this->networks->contract($tenantId, $contractId);
            if ($c->provider_profile_id !== $providerId || $c->status !== 'ACTIVE') {
                throw new ApiProblemException('CONTRACT_INVALID', 422, 'The contract is not an active contract of this provider.');
            }

            return $c;
        }

        return DB::table('provider_contracts')->where('tenant_id', $tenantId)->where('provider_profile_id', $providerId)->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))
            ->orderByDesc('effective_from')->first();
    }

    /** @return array<string, mixed> priced line (not yet stored) */
    private function priceLine(string $tenantId, string $providerId, ?object $contract, array $l, int $no, string $day): array
    {
        $providerCode = $l['provider_code'] ?? null;
        $service = $providerCode !== null && $providerCode !== ''
            ? $this->networks->resolveProviderCode($providerId, $providerCode)
            : DB::table('medical_services')->where('code', strtoupper((string) ($l['service_code'] ?? '')))->first();
        if (! $service || $service->status !== 'ACTIVE') {
            throw new ApiProblemException('MEDICAL_SERVICE_UNKNOWN', 422, "Line {$no}: unknown or inactive medical service ".($providerCode ?? ($l['service_code'] ?? '')).'.');
        }
        $qty = (float) $l['quantity'];
        if ($qty <= 0) {
            throw new ApiProblemException('QUANTITY_INVALID', 422, "Line {$no}: quantity must be positive.");
        }
        $price = $contract ? $this->networks->priceFor($tenantId, $contract->id, $service->id, $day) : null;
        if ($price) {
            $unit = (int) $price->contracted_price_minor;
            $copay = (int) $price->copay_minor;
            $share = (float) $price->insurer_share_percent;
            $from = 'TARIFF';
        } elseif (isset($l['unit_price_minor'])) {
            // Out-of-tariff (no contract, or service not in the approved tariff): the requested price is flagged for review.
            [$unit, $copay, $share, $from] = [(int) $l['unit_price_minor'], 0, 100.0, 'REQUESTED'];
        } else {
            throw new ApiProblemException('TARIFF_MISSING', 409, "Line {$no}: no approved tariff prices {$service->code} under the provider contract; give unit_price_minor for an out-of-tariff request.");
        }
        $gross = (int) round($unit * $qty);

        return [
            'line_no' => $no, 'medical_service_id' => $service->id, 'service_code' => $service->code, 'category_code' => $service->category_code,
            'provider_code' => $providerCode, 'quantity' => $qty, 'priced_from' => $from, 'tariff_line_id' => $price->id ?? null,
            'unit_price_minor' => $unit, 'copay_minor' => $copay, 'insurer_share_percent' => $share, 'requested_amount_minor' => $gross,
            'insurer_amount_minor' => (int) round(max(0, ($unit - $copay) * $qty) * $share / 100), 'currency' => $price->currency ?? null,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function insertLines(string $preauthId, ?string $extId, array $lines): void
    {
        foreach ($lines as $l) {
            unset($l['currency']);
            DB::table('health_preauthorization_lines')->insert(array_merge($l, [
                'id' => (string) Str::uuid(), 'health_preauthorization_id' => $preauthId, 'extension_id' => $extId,
                'eligibility' => json_encode($l['eligibility'] ?? [], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }

    /** @return array<string, mixed> */
    private function eligibility(string $tenantId, Policy $policy, string $memberRef, string $providerId, string $serviceCode, string $day): array
    {
        if (class_exists(self::ELIGIBILITY)) {
            $r = app(self::ELIGIBILITY)->check($tenantId, $memberRef, $providerId, $serviceCode, CarbonImmutable::parse($day));

            return ['source' => 'ELIGIBILITY_SERVICE', 'eligible' => (bool) ($r['eligible'] ?? false)] + $r;
        }
        // Fallback (E2 not deployed): policy of this tenant in force on the service date.
        $reasons = [];
        if (! in_array($policy->status, self::IN_FORCE, true)) {
            $reasons[] = 'POLICY_NOT_IN_FORCE';
        }
        if ($policy->coverage_starts_at && $day < $policy->coverage_starts_at->toDateString() || $policy->coverage_ends_at && $day > $policy->coverage_ends_at->toDateString()) {
            $reasons[] = 'OUTSIDE_COVERAGE_PERIOD';
        }

        return ['source' => 'POLICY_FALLBACK', 'eligible' => $reasons === [], 'reasons' => $reasons, 'policy_id' => $policy->id, 'checked_on' => $day];
    }

    /**
     * @param  iterable<object>  $lines
     * @param  list<array<string, mixed>>  $input
     * @return array<string, array<string, mixed>> line id => changes
     */
    private function decideLines(iterable $lines, string $decision, array $input): array
    {
        $byId = collect($input)->keyBy('line_id');
        $out = [];
        foreach ($lines as $l) {
            $in = $byId->get($l->id);
            if ($decision === 'APPROVED') {
                $out[$l->id] = ['line_decision' => 'APPROVED', 'approved_quantity' => $l->quantity, 'approved_amount_minor' => (int) $l->insurer_amount_minor, 'decline_reason' => null];

                continue;
            }
            if ($decision === 'DECLINED') {
                $out[$l->id] = ['line_decision' => 'DECLINED', 'approved_quantity' => 0, 'approved_amount_minor' => 0, 'decline_reason' => $in['decline_reason'] ?? null];

                continue;
            }
            // PARTIAL: lines not listed are approved in full; a listed line may be reduced (quantity and/or amount) or declined.
            $qty = isset($in['approved_quantity']) ? (float) $in['approved_quantity'] : (float) $l->quantity;
            if ($qty < 0 || $qty > (float) $l->quantity) {
                throw new ApiProblemException('LINE_DECISION_INVALID', 422, "Line {$l->line_no}: approved quantity must be between 0 and the requested quantity.");
            }
            $max = (int) round((int) $l->insurer_amount_minor * ($qty / (float) $l->quantity));
            $amount = isset($in['approved_amount_minor']) ? (int) $in['approved_amount_minor'] : $max;
            if ($amount < 0 || $amount > $max) {
                throw new ApiProblemException('LINE_DECISION_INVALID', 422, "Line {$l->line_no}: approved amount must be between 0 and {$max}.");
            }
            $lineDecision = $amount === 0 ? 'DECLINED' : ($amount < (int) $l->insurer_amount_minor ? 'PARTIAL' : 'APPROVED');
            if ($lineDecision !== 'APPROVED' && trim((string) ($in['decline_reason'] ?? '')) === '') {
                throw new ApiProblemException('REASON_REQUIRED', 422, "Line {$l->line_no}: a reduced or declined line needs a decline_reason.");
            }
            $out[$l->id] = ['line_decision' => $lineDecision, 'approved_quantity' => $qty, 'approved_amount_minor' => $amount, 'decline_reason' => $in['decline_reason'] ?? null];
        }
        if ($decision === 'PARTIAL') {
            $total = array_sum(array_column($out, 'approved_amount_minor'));
            $full = collect($lines)->sum(fn ($l) => (int) $l->insurer_amount_minor);
            if ($total === 0 || $total >= $full) {
                throw new ApiProblemException('PARTIAL_INVALID', 422, 'A partial decision must approve some but not all of the requested amount.');
            }
        }

        return $out;
    }

    /**
     * GOP validity window. No platform default is invented: the reviewer gives it, or the insurer configures
     * health_preauth.gop_validity_days (unset by default).
     *
     * @return array{0: string, 1: string}
     */
    private function validity(string $serviceDate, ?string $from, ?string $until): array
    {
        $from = CarbonImmutable::parse($from ?? $serviceDate)->toDateString();
        if ($until === null) {
            $days = config('health_preauth.gop_validity_days');
            if ($days === null || (int) $days <= 0) {
                throw new ApiProblemException('GOP_VALIDITY_REQUIRED', 422, 'valid_until is required: no GOP validity period is configured (health_preauth.gop_validity_days).');
            }
            $until = CarbonImmutable::parse($from)->addDays((int) $days)->toDateString();
        }
        $until = CarbonImmutable::parse($until)->toDateString();
        if ($until < $from) {
            throw new ApiProblemException('GOP_VALIDITY_INVALID', 422, 'valid_until must not be before valid_from.');
        }

        return [$from, $until];
    }

    /**
     * Checker's own limit. Over limit on a proposal awaiting approval → the check opens an AUTHORITY_REFERRAL case and the
     * caller refers the item; over limit on an already REFERRED item (supervisor) → refused.
     */
    private function checkerAuthority(object $pa, int $amount, User $actor, string $subjectId, string $action, bool $alreadyReferred): ?\App\Application\Authority\AuthorityOutcome
    {
        if ($amount <= 0) {
            return null;
        }
        $check = $this->authority->checkStaffLimit($pa->tenant_id, (string) $pa->carrier_id, $actor, PreauthLifecycle::authorityType(), $amount, $pa->currency,
            ['type' => 'health_preauthorization', 'id' => $subjectId, 'title' => 'Preauthorization '.$pa->preauth_number], $action);
        if ($check->denied() || ($alreadyReferred && ! $check->allowed())) {
            throw new ApiProblemException('AUTHORITY_EXCEEDED', 403, 'The amount exceeds your preauthorization authority.', [], ['reason' => $check->reason, 'max_amount_minor' => $check->maxAmountMinor]);
        }

        return $check;
    }

    /**
     * Benefit reservation through the E5 BenefitAccumulator when deployed: one reservation per approved line, keyed by
     * member + benefit (medical service category) + the preauthorization reference. A business refusal (limit exhausted)
     * propagates and blocks the approval.
     *
     * @param  list<object>  $lines
     * @return list<array<string, mixed>>
     */
    private function reserve(object $pa, array $lines, string $reference): array
    {
        if (! class_exists(self::ACCUMULATOR) || ! method_exists(self::ACCUMULATOR, 'reserve')) {
            return array_map(fn ($l) => ['line_id' => $l->id, 'benefit_code' => $l->category_code, 'amount_minor' => (int) $l->approved_amount_minor, 'status' => 'NOT_RESERVED', 'reason' => 'ACCUMULATOR_UNAVAILABLE'], $lines);
        }
        $acc = app(self::ACCUMULATOR);
        $out = [];
        foreach ($lines as $l) {
            $r = $acc->reserve($pa->tenant_id, $pa->member_ref, $l->category_code, (int) $l->approved_amount_minor, $pa->currency, 'health_preauthorization', $reference, CarbonImmutable::parse($pa->service_date));
            $out[] = ['line_id' => $l->id, 'benefit_code' => $l->category_code, 'amount_minor' => (int) $l->approved_amount_minor, 'status' => 'RESERVED',
                'reservation' => is_object($r) ? ($r->id ?? null) : (is_array($r) ? ($r['id'] ?? null) : $r)];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function release(object $pa): array
    {
        $rows = json_decode((string) $pa->benefit_reservations, true) ?: [];
        $canRelease = class_exists(self::ACCUMULATOR) && method_exists(self::ACCUMULATOR, 'release');
        foreach ($rows as &$r) {
            if (($r['status'] ?? null) === 'RESERVED' && $canRelease) {
                app(self::ACCUMULATOR)->release($pa->tenant_id, $r['reservation'] ?? null);
                $r['status'] = 'RELEASED';
            }
        }

        return $rows;
    }

    private function fireDocuments(string $trigger, object $pa, ?object $ext, User $actor, array $include): ?object
    {
        $policy = Policy::find($pa->policy_id);
        if (! $policy) {
            return null;
        }

        return app(DocumentEngine::class)->fireQuietly($trigger, $policy, [
            'preauth' => $pa, 'extension' => $ext, 'include' => $include,
            'subject' => ['type' => 'PREAUTHORIZATION', 'key' => 'preauth:'.$pa->id.($ext ? ':ext:'.$ext->sequence : ''), 'label' => $pa->preauth_number.($ext ? ' / EXT '.$ext->sequence : '')],
            'valid_from' => $trigger === 'PREAUTH_DECLINED' ? null : $pa->gop_valid_from,
            'valid_until' => $trigger === 'PREAUTH_DECLINED' ? null : ($ext->approved_until ?? $pa->gop_valid_until),
        ], $actor);
    }

    private function lock(string $tenantId, string $id): object
    {
        return DB::table('health_preauthorizations')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiProblemException('PREAUTH_NOT_FOUND', 404, 'Preauthorization not found.');
    }

    /** @param list<string> $states */
    private function lockExtension(string $preauthId, string $extId, array $states): object
    {
        $e = DB::table('health_preauthorization_extensions')->where('health_preauthorization_id', $preauthId)->where('id', $extId)->lockForUpdate()->first()
            ?? throw new ApiProblemException('EXTENSION_NOT_FOUND', 404, 'Stay extension not found.');
        if (! in_array($e->status, $states, true)) {
            throw new ApiProblemException('EXTENSION_TRANSITION_INVALID', 409, "The extension is {$e->status}.");
        }

        return $e;
    }

    private function assertNotRequester(object $pa, User $actor): void
    {
        if ($pa->requested_by === $actor->id) {
            throw new ApiProblemException('MAKER_CHECKER', 403, 'The requester cannot review their own preauthorization.');
        }
    }

    private function assertChecker(object $pa, User $actor): void
    {
        if (in_array($actor->id, [$pa->proposed_by, $pa->requested_by], true)) {
            throw new ApiProblemException('MAKER_CHECKER', 403, 'The maker or requester of a preauthorization cannot decide it.');
        }
        if ($pa->status === 'REFERRED' && ! $actor->hasPermission('health.preauth.supervise')) {
            throw new ApiProblemException('AUTHORITY_SUPERVISOR_REQUIRED', 403, 'A referred preauthorization needs a supervisor decision.');
        }
    }

    private function assertAdmission(object $pa): void
    {
        if ($pa->request_type !== 'ADMISSION') {
            throw new ApiProblemException('NOT_ADMISSION', 409, 'Only an ADMISSION preauthorization has admission, extension and discharge steps.');
        }
    }

    private function move(string $tenantId, string $id, string $event, User $actor, ?string $reason, callable $changes, array $payload = []): object
    {
        return DB::transaction(function () use ($tenantId, $id, $event, $actor, $reason, $changes, $payload) {
            $pa = $this->lock($tenantId, $id);

            return $this->transition($pa, $event, $actor, $reason, $changes($pa), $payload);
        });
    }

    /** @param array<string, mixed> $changes */
    private function transition(object $pa, string $event, User $actor, ?string $reason, array $changes, array $payload = []): object
    {
        $to = PreauthLifecycle::target($pa->status, $event);
        if ($to === null) {
            throw new ApiProblemException('PREAUTH_TRANSITION_INVALID', 409, "Cannot {$event} a preauthorization that is {$pa->status}.", [], ['from' => $pa->status, 'event' => $event]);
        }
        if (in_array($event, PreauthLifecycle::REASON_REQUIRED, true) && trim((string) $reason) === '') {
            throw new ApiProblemException('REASON_REQUIRED', 422, "A reason is required to {$event}.");
        }
        DB::table('health_preauthorizations')->where('id', $pa->id)->update(array_merge($changes, ['status' => $to, 'version' => $pa->version + 1, 'updated_at' => now()]));
        $this->caseStep($pa->case_id, $pa->status, $event, $actor, $reason);
        $this->event($pa->id, null, $event, $pa->status, $to, $actor, $reason, $payload);
        $this->publish($event, $pa->id, ['from' => $pa->status, 'to' => $to, 'provider_id' => $pa->provider_profile_id, 'policy_id' => $pa->policy_id] + $payload);

        return $this->find($pa->tenant_id, $pa->id);
    }

    /** Move the SLA case alongside (only while it mirrors the record; ADMITTED/DISCHARGED are not case states). */
    private function caseStep(?string $caseId, string $from, string $event, User $actor, ?string $reason): void
    {
        if (! $caseId) {
            return;
        }
        $case = WorkCase::withoutGlobalScopes()->find($caseId);
        if ($case && $case->status === $from && $case->closed_at === null) {
            $this->cases->transition($case, $event, $actor, $reason);
        }
    }

    private function event(string $id, ?string $extId, string $event, ?string $from, string $to, User $actor, ?string $reason, array $payload): void
    {
        DB::table('health_preauthorization_events')->insert([
            'id' => (string) Str::uuid(), 'health_preauthorization_id' => $id, 'extension_id' => $extId, 'event' => $event, 'from_status' => $from,
            'to_status' => $to, 'actor_id' => $actor->id, 'reason' => $reason, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    }

    private function publish(string $event, string $id, array $data): void
    {
        $name = PreauthLifecycle::DOMAIN_EVENTS[$event];
        $data = ['preauthorization_id' => $id] + $data;
        $this->audit->record($name, 'health_preauthorization', $id, $data);
        $this->outbox->record($name, 'health_preauthorization', $id, $data);
    }
}
