<?php

declare(strict_types=1);

namespace App\Application\Reinsurance\Facultative;

use App\Application\Audit\AuditWriter;
use App\Application\Authority\AuthorityService;
use App\Application\Events\OutboxWriter;
use App\Application\Ledger\FinancialPostingService;
use App\Application\Reinsurance\CessionCalculator;
use App\Application\Reinsurance\CessionService;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-REI-003: facultative placements. Separate from treaties (TreatyService still rejects reinsurance_type FACULTATIVE).
 *
 *  DRAFT      maker records the slip (risk, 100% sum insured / premium, placed share, terms, period) and the
 *             participants' OFFERED lines; then their WRITTEN lines as the market answers.
 *  SUBMITTED  written lines are signed: written total < 100% of the placed share → refused (under-placed);
 *             > 100% (oversubscribed) → every line is signed down proportionally; signed lines total exactly 100%.
 *  BOUND      a second person (permission reinsurance.facultative.approve, not the maker) approves under the
 *             FACULTATIVE_APPROVE authority (AuthorityService::checkStaffLimit on the ceded sum insured: over limit /
 *             no limit → REFERRED with an AUTHORITY_REFERRAL case, placement stays SUBMITTED). Binding records the
 *             cession through CessionService::recordFacultative (reinsurance_cessions, source FACULTATIVE) and posts
 *             reinsurance.facultative.bound (ceded premium) through FinancialPostingService.
 *  REJECTED   checker rejects with a reason.
 *
 * All line percentages are of the placed share (100 = the whole facultative share).
 */
final class FacultativePlacementService
{
    public const AUTHORITY_TYPE = 'FACULTATIVE_APPROVE';

    public function __construct(
        private readonly CessionService $cessions,
        private readonly AuthorityService $authority,
        private readonly FinancialPostingService $posting,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function create(string $tenantId, array $d): array
    {
        $policy = DB::table('policies')->where('tenant_id', $tenantId)->where('id', $d['policy_id'])->first() ?? abort(404, 'Policy not found.');
        $this->require(! in_array($policy->status, ['DRAFT', 'PENDING_PAYMENT', 'QUOTED'], true), 'policy', 'Only issued policies can be placed facultatively.');
        $currency = strtoupper($d['currency'] ?? $policy->currency);
        $this->require($currency === $policy->currency, 'currency', 'The slip currency must be the policy currency.');
        $terms = json_decode((string) $policy->terms_snapshot, true) ?: [];
        $sumInsured = $d['sum_insured_minor'] ?? (is_numeric($terms['sum_insured_minor'] ?? null) ? (int) $terms['sum_insured_minor'] : null);
        $this->require($sumInsured !== null && $sumInsured > 0, 'sum_insured_minor', 'The slip needs the sum insured.');
        foreach (['commission_percent', 'brokerage_percent', 'tax_percent'] as $k) {
            $this->require((float) ($d[$k] ?? 0) >= 0 && (float) ($d[$k] ?? 0) <= 100, $k, "{$k} must be between 0 and 100.");
        }
        $this->require((float) ($d['commission_percent'] ?? 0) + (float) ($d['brokerage_percent'] ?? 0) + (float) ($d['tax_percent'] ?? 0) <= 100, 'commission_percent', 'Deductions exceed the ceded premium.');
        $this->require($d['period_to'] >= $d['period_from'], 'period_to', 'Period end is before its start.');
        $reference = $d['reference'] ?? 'FAC-'.Str::upper(Str::random(8));
        $this->require(! DB::table('facultative_placements')->where('tenant_id', $tenantId)->where('reference', $reference)->exists(), 'reference', 'Slip reference already used.');
        if (! empty($d['broker_id'])) {
            $this->require(DB::table('reinsurers')->where('tenant_id', $tenantId)->where('id', $d['broker_id'])->where('role', 'REINSURANCE_BROKER')->exists(), 'broker_id', 'Broker must be a REINSURANCE_BROKER of this tenant.');
        }
        $participants = $d['participants'] ?? [];
        $this->validateParticipants($tenantId, $participants);

        return DB::transaction(function () use ($tenantId, $d, $policy, $currency, $sumInsured, $reference, $participants) {
            $id = (string) Str::uuid();
            DB::table('facultative_placements')->insert(['id' => $id, 'tenant_id' => $tenantId, 'policy_id' => $policy->id, 'reference' => $reference, 'status' => 'DRAFT',
                'risk_description' => $d['risk_description'], 'currency' => $currency, 'sum_insured_minor' => $sumInsured, 'premium_minor' => $d['premium_minor'] ?? (int) $policy->premium_minor,
                'placed_share_percent' => $d['placed_share_percent'], 'commission_percent' => $d['commission_percent'] ?? 0, 'brokerage_percent' => $d['brokerage_percent'] ?? 0,
                'tax_percent' => $d['tax_percent'] ?? 0, 'terms' => json_encode((object) ($d['terms'] ?? [])), 'period_from' => $d['period_from'], 'period_to' => $d['period_to'],
                'broker_id' => $d['broker_id'] ?? null, 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($participants as $p) {
                DB::table('facultative_participants')->insert(['id' => (string) Str::uuid(), 'placement_id' => $id, 'reinsurer_id' => $p['reinsurer_id'],
                    'offered_percent' => $p['offered_percent'], 'written_percent' => $p['written_percent'] ?? null, 'is_lead' => (bool) ($p['is_lead'] ?? false),
                    'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('reinsurance.facultative.created', 'facultative_placement', $id, ['policy_id' => $policy->id, 'reference' => $reference, 'placed_share_percent' => $d['placed_share_percent']]);

            return $this->show($tenantId, $id);
        });
    }

    /** Record written lines (percent of the placed share) as reinsurers answer the offer. DRAFT only. */
    public function recordWrittenLines(string $tenantId, string $id, array $lines): array
    {
        $pl = $this->placement($tenantId, $id);
        $this->require($pl->status === 'DRAFT', 'status', 'Written lines can only change while the slip is DRAFT.');
        foreach ($lines as $l) {
            $n = DB::table('facultative_participants')->where('placement_id', $id)->where('reinsurer_id', $l['reinsurer_id'])
                ->update(['written_percent' => $l['written_percent'], 'updated_at' => now()]);
            $this->require($n === 1, 'lines', 'Written lines must reference a participant of the slip.');
        }
        $this->audit->record('reinsurance.facultative.lines_written', 'facultative_placement', $id, ['lines' => $lines]);

        return $this->show($tenantId, $id);
    }

    /** Sign the written lines (signing down when oversubscribed) and submit for approval. */
    public function submit(string $tenantId, string $id): array
    {
        return DB::transaction(function () use ($tenantId, $id) {
            $pl = $this->placement($tenantId, $id, lock: true);
            $this->require($pl->status === 'DRAFT', 'status', 'Only a DRAFT slip can be submitted.');
            $parts = DB::table('facultative_participants')->where('placement_id', $id)->orderBy('created_at')->orderBy('id')->get();
            $this->require($parts->isNotEmpty(), 'participants', 'At least one participant is required.');
            $this->require($parts->every(fn ($p) => $p->written_percent !== null), 'participants', 'Every participant needs a written line (0 to decline).');
            $written = array_map(fn ($p) => (float) $p->written_percent, $parts->all());
            $signed = self::signLines($written);
            foreach (array_values($parts->all()) as $i => $p) {
                DB::table('facultative_participants')->where('id', $p->id)->update(['signed_percent' => $signed[$i], 'updated_at' => now()]);
            }
            DB::table('facultative_placements')->where('id', $id)->update(['status' => 'SUBMITTED', 'submitted_by' => auth()->id(), 'submitted_at' => now(), 'updated_at' => now()]);
            $this->audit->recordChange('reinsurance.facultative.submitted', 'facultative_placement', $id, ['status' => 'DRAFT'], ['status' => 'SUBMITTED', 'written_total' => round(array_sum($written), 4)], 'Slip submitted with signed lines.');
            $this->outbox->record('reinsurance.facultative.submitted', 'facultative_placement', $id, ['tenant_id' => $tenantId, 'policy_id' => $pl->policy_id,
                'written_total_percent' => round(array_sum($written), 4), 'signed_down' => array_sum($written) > 100.00005]);

            return $this->show($tenantId, $id);
        });
    }

    /**
     * Signed lines from written lines (percent of the placed share): under 100% → refused; over 100% → each line
     * signed down proportionally (written × 100 / total), largest-remainder at 4 dp so the total is exactly 100.
     *
     * @param  list<float>  $written
     * @return list<float>
     */
    public static function signLines(array $written): array
    {
        $scale = 10_000; // 4 dp
        $units = array_map(fn ($w) => (int) round($w * $scale), $written);
        $total = array_sum($units);
        if ($total < 100 * $scale) {
            throw ValidationException::withMessages(['participants' => 'Written lines total '.($total / $scale).'%, the placed share must be 100% covered.']);
        }
        if ($total === 100 * $scale) {
            return array_map(fn ($u) => (float) ($u / $scale), $units);
        }
        $target = 100 * $scale;
        $floor = [];
        $rem = [];
        foreach ($units as $i => $u) {
            $exact = $u * $target / $total;
            $floor[$i] = (int) floor($exact);
            $rem[$i] = $exact - $floor[$i];
        }
        arsort($rem);
        $left = $target - array_sum($floor);
        foreach (array_keys($rem) as $i) {
            if ($left-- <= 0) {
                break;
            }
            $floor[$i]++;
        }
        ksort($floor);

        return array_map(fn ($u) => (float) ($u / $scale), array_values($floor));
    }

    /** Checker approval under FACULTATIVE_APPROVE; binds the placement and records the cession + ledger posting. */
    public function approve(string $tenantId, string $id, User $checker, string $reason): array
    {
        $pl = $this->placement($tenantId, $id);
        $this->require($pl->status === 'SUBMITTED', 'status', 'Only a SUBMITTED slip can be approved.');
        $this->require($pl->created_by === null || $pl->created_by !== $checker->id, 'approver', 'The maker of a facultative slip cannot approve it.');
        $this->require($pl->submitted_by === null || $pl->submitted_by !== $checker->id, 'approver', 'The submitter of a facultative slip cannot approve it.');
        $parts = DB::table('facultative_participants')->where('placement_id', $id)->where('signed_percent', '>', 0)->get();
        $this->require(! DB::table('reinsurers')->whereIn('id', $parts->pluck('reinsurer_id'))->where('status', '!=', 'ACTIVE')->exists(), 'participants', 'All signed reinsurers must be ACTIVE.');
        $policy = DB::table('policies')->where('id', $pl->policy_id)->first();
        $amounts = $this->amounts($pl);

        $check = $this->authority->checkStaffLimit($tenantId, (string) $policy->carrier_id, $checker, self::AUTHORITY_TYPE, $amounts['ceded_sum_minor'], $pl->currency,
            ['type' => 'facultative_placement', 'id' => $id, 'title' => 'Facultative placement '.$pl->reference], 'FACULTATIVE_BIND',
            json_decode((string) $policy->terms_snapshot, true)['line_code'] ?? null);
        DB::table('facultative_placements')->where('id', $id)->update(['authority_check_id' => $check->checkId, 'updated_at' => now()]);
        if (! $check->allowed()) {
            throw new ApiProblemException('FACULTATIVE_AUTHORITY_REFERRED', 409, 'Facultative approval exceeds your FACULTATIVE_APPROVE authority; it was referred.', [],
                ['authority' => $check->snapshot()]);
        }

        return DB::transaction(function () use ($tenantId, $id, $pl, $policy, $parts, $amounts, $reason, $checker) {
            $locked = $this->placement($tenantId, $id, lock: true);
            $this->require($locked->status === 'SUBMITTED', 'status', 'Only a SUBMITTED slip can be approved.');
            $shares = $this->split($parts->all(), $amounts);
            $cessionId = $this->cessions->recordFacultative($tenantId, $pl->policy_id, ['placement_id' => $id, 'policy_version' => (int) $policy->version] + $amounts, $shares);
            $journal = $this->posting->post($tenantId, 'reinsurance.facultative.bound', $id, $amounts['ceded_premium_minor'], $pl->currency, $id);
            DB::table('facultative_placements')->where('id', $id)->update(['status' => 'BOUND', 'decided_by' => $checker->id, 'decided_at' => now(), 'decision_reason' => $reason,
                'journal_id' => $journal, 'updated_at' => now()]);
            $this->audit->recordChange('reinsurance.facultative.bound', 'facultative_placement', $id, ['status' => 'SUBMITTED'], ['status' => 'BOUND', 'cession_id' => $cessionId], $reason);
            $this->outbox->record('reinsurance.facultative.bound', 'facultative_placement', $id, ['tenant_id' => $tenantId, 'policy_id' => $pl->policy_id, 'cession_id' => $cessionId,
                'currency' => $pl->currency, 'ceded_sum_minor' => $amounts['ceded_sum_minor'], 'ceded_premium_minor' => $amounts['ceded_premium_minor'], 'journal_id' => $journal]);

            return $this->show($tenantId, $id);
        });
    }

    public function reject(string $tenantId, string $id, User $checker, string $reason): array
    {
        return DB::transaction(function () use ($tenantId, $id, $checker, $reason) {
            $pl = $this->placement($tenantId, $id, lock: true);
            $this->require($pl->status === 'SUBMITTED', 'status', 'Only a SUBMITTED slip can be rejected.');
            $this->require($pl->created_by === null || $pl->created_by !== $checker->id, 'approver', 'The maker of a facultative slip cannot decide it.');
            DB::table('facultative_placements')->where('id', $id)->update(['status' => 'REJECTED', 'decided_by' => $checker->id, 'decided_at' => now(), 'decision_reason' => $reason, 'updated_at' => now()]);
            $this->audit->recordChange('reinsurance.facultative.rejected', 'facultative_placement', $id, ['status' => 'SUBMITTED'], ['status' => 'REJECTED'], $reason);
            $this->outbox->record('reinsurance.facultative.rejected', 'facultative_placement', $id, ['tenant_id' => $tenantId, 'policy_id' => $pl->policy_id]);

            return $this->show($tenantId, $id);
        });
    }

    public function show(string $tenantId, string $id): array
    {
        $a = (array) $this->placement($tenantId, $id);
        $a['terms'] = json_decode((string) $a['terms'], true);
        $a['period_from'] = substr((string) $a['period_from'], 0, 10);
        $a['period_to'] = substr((string) $a['period_to'], 0, 10);
        $a['placed_share_percent'] = (float) $a['placed_share_percent'];
        $a['participants'] = DB::table('facultative_participants')->where('placement_id', $id)->orderByDesc('is_lead')->orderBy('created_at')->get()->map(function ($p) {
            $p = (array) $p;
            foreach (['offered_percent', 'written_percent', 'signed_percent'] as $k) {
                $p[$k] = $p[$k] === null ? null : (float) $p[$k];
            }

            return $p;
        })->all();
        $a['cession_id'] = DB::table('reinsurance_cessions')->where('facultative_placement_id', $id)->value('id');

        return $a;
    }

    public function list(string $tenantId, ?string $policyId = null): array
    {
        return DB::table('facultative_placements')->where('tenant_id', $tenantId)->when($policyId, fn ($q) => $q->where('policy_id', $policyId))
            ->orderByDesc('created_at')->get()->all();
    }

    private function placement(string $tenantId, string $id, bool $lock = false): object
    {
        $q = DB::table('facultative_placements')->where('tenant_id', $tenantId)->where('id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? abort(404, 'Facultative placement not found.');
    }

    /** Ceded amounts of the whole placed share. */
    private function amounts(object $pl): array
    {
        $share = (float) $pl->placed_share_percent;
        $cededSum = CessionCalculator::pct((int) $pl->sum_insured_minor, $share);
        $cededPremium = CessionCalculator::pct((int) $pl->premium_minor, $share);
        $commission = CessionCalculator::pct($cededPremium, (float) $pl->commission_percent);
        $brokerage = CessionCalculator::pct($cededPremium, (float) $pl->brokerage_percent);
        $tax = CessionCalculator::pct($cededPremium, (float) $pl->tax_percent);

        return ['currency' => $pl->currency, 'sum_insured_minor' => (int) $pl->sum_insured_minor, 'gross_premium_minor' => (int) $pl->premium_minor, 'ceded_percent' => $share,
            'ceded_sum_minor' => $cededSum, 'ceded_premium_minor' => $cededPremium, 'commission_minor' => $commission, 'brokerage_minor' => $brokerage, 'tax_minor' => $tax,
            'net_ceded_premium_minor' => $cededPremium - $commission - $brokerage - $tax];
    }

    /** Largest-remainder split of each amount across signed lines so shares reconcile to the cession. */
    private function split(array $parts, array $amounts): array
    {
        $keys = ['ceded_sum_minor', 'ceded_premium_minor', 'commission_minor', 'brokerage_minor', 'tax_minor'];
        $out = array_map(fn ($p) => ['reinsurer_id' => $p->reinsurer_id, 'share_percent' => (float) $p->signed_percent], $parts);
        foreach ($keys as $k) {
            $floor = [];
            $rem = [];
            foreach ($parts as $i => $p) {
                $exact = $amounts[$k] * (float) $p->signed_percent / 100;
                $floor[$i] = (int) floor($exact);
                $rem[$i] = $exact - $floor[$i];
            }
            arsort($rem);
            $left = $amounts[$k] - array_sum($floor);
            foreach (array_keys($rem) as $i) {
                if ($left-- <= 0) {
                    break;
                }
                $floor[$i]++;
            }
            foreach ($floor as $i => $v) {
                $out[$i][$k] = $v;
            }
        }
        foreach ($out as &$o) {
            $o['net_premium_minor'] = $o['ceded_premium_minor'] - $o['commission_minor'] - $o['brokerage_minor'] - $o['tax_minor'];
        }

        return array_values($out);
    }

    private function validateParticipants(string $tenantId, array $participants): void
    {
        $this->require($participants !== [], 'participants', 'At least one participant is required.');
        foreach ($participants as $p) {
            $r = DB::table('reinsurers')->where('tenant_id', $tenantId)->where('id', $p['reinsurer_id'] ?? null)->first();
            $this->require($r !== null && $r->role !== 'REINSURANCE_BROKER', 'participants', 'Participants must be reinsurers of this tenant.');
            $this->require((float) ($p['offered_percent'] ?? 0) > 0 && (float) $p['offered_percent'] <= 100, 'participants', 'Offered lines must be between 0 and 100% of the placed share.');
        }
        $this->require(count(array_unique(array_column($participants, 'reinsurer_id'))) === count($participants), 'participants', 'A reinsurer may participate once per slip.');
    }

    private function require(bool $ok, string $field, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
