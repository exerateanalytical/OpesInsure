<?php

declare(strict_types=1);

namespace App\Application\Reinsurance;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-REI-002: risk cessions. Reads the policy (premium_minor, currency, coverage_starts_at, version,
 * terms_snapshot.line_code / sum_insured_minor) and never writes to it. Public entry point for other
 * modules (issuance, endorsements) to call after a policy changes: cedePolicy().
 *
 * Idempotent: a run whose inputs (policy version, premium, sum insured, treaty versions) match the
 * current CALCULATED run returns that run; otherwise the previous run is SUPERSEDED and a new run recorded.
 */
final class CessionService
{
    public function __construct(
        private readonly TreatyService $treaties,
        private readonly CessionCalculator $calculator,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** Calculate without persisting. */
    public function preview(string $tenantId, string $policyId, ?int $sumInsured = null): array
    {
        [$policy, $basis, $versions] = $this->inputs($tenantId, $policyId, $sumInsured);
        $cessions = $this->calculator->calculate($basis['sum_insured_minor'], $basis['gross_premium_minor'], $versions);

        return $this->summary($policy, $basis, $cessions, null);
    }

    public function cedePolicy(string $tenantId, string $policyId, ?int $sumInsured = null): array
    {
        return DB::transaction(function () use ($tenantId, $policyId, $sumInsured) {
            [$policy, $basis, $versions] = $this->inputs($tenantId, $policyId, $sumInsured, lock: true);
            $cessions = $this->calculator->calculate($basis['sum_insured_minor'], $basis['gross_premium_minor'], $versions);

            $current = DB::table('reinsurance_cessions')->where('policy_id', $policyId)->where('status', 'CALCULATED')->get();
            if ($current->isNotEmpty() && $this->sameRun($current, $policy, $basis, $cessions)) {
                return $this->summary($policy, $basis, $this->load($policyId, (int) $current->first()->run), (int) $current->first()->run, replayed: true);
            }
            if ($cessions === [] && $current->isEmpty()) {
                return $this->summary($policy, $basis, [], null);
            }

            $run = (int) DB::table('reinsurance_cessions')->where('policy_id', $policyId)->max('run') + 1;
            DB::table('reinsurance_cessions')->where('policy_id', $policyId)->where('status', 'CALCULATED')->update(['status' => 'SUPERSEDED']);
            foreach ($cessions as $c) {
                $id = (string) Str::uuid();
                DB::table('reinsurance_cessions')->insert(['id' => $id, 'tenant_id' => $tenantId, 'policy_id' => $policyId, 'policy_version' => (int) $policy->version, 'run' => $run,
                    'treaty_id' => $c['treaty_id'], 'treaty_version_id' => $c['treaty_version_id'], 'treaty_type' => $c['treaty_type'], 'currency' => $basis['currency'],
                    'sum_insured_minor' => $basis['sum_insured_minor'], 'gross_premium_minor' => $basis['gross_premium_minor'], 'subject_sum_minor' => $c['subject_sum_minor'],
                    'ceded_sum_minor' => $c['ceded_sum_minor'], 'ceded_percent' => $c['ceded_percent'], 'ceded_premium_minor' => $c['ceded_premium_minor'],
                    'commission_minor' => $c['commission_minor'], 'brokerage_minor' => $c['brokerage_minor'], 'tax_minor' => $c['tax_minor'],
                    'net_ceded_premium_minor' => $c['net_ceded_premium_minor'], 'layers' => json_encode($c['layers']), 'status' => 'CALCULATED',
                    'calculated_by' => auth()->id(), 'created_at' => now()]);
                foreach ($c['shares'] as $s) {
                    DB::table('reinsurance_cession_shares')->insert(['id' => (string) Str::uuid(), 'cession_id' => $id, 'reinsurer_id' => $s['reinsurer_id'], 'share_percent' => $s['share_percent'],
                        'ceded_sum_minor' => $s['ceded_sum_minor'], 'ceded_premium_minor' => $s['ceded_premium_minor'], 'commission_minor' => $s['commission_minor'],
                        'brokerage_minor' => $s['brokerage_minor'], 'tax_minor' => $s['tax_minor'], 'net_premium_minor' => $s['net_premium_minor'], 'created_at' => now()]);
                }
            }
            $summary = $this->summary($policy, $basis, $this->load($policyId, $run), $run);
            $this->audit->record('reinsurance.policy.ceded', 'policy', $policyId, ['run' => $run, 'treaty_versions' => array_column($cessions, 'treaty_version_id'),
                'ceded_premium_minor' => $summary['ceded_premium_minor'], 'currency' => $basis['currency']]);
            $this->outbox->record('reinsurance.policy.ceded', 'policy', $policyId, ['tenant_id' => $tenantId, 'run' => $run, 'currency' => $basis['currency'],
                'gross_premium_minor' => $basis['gross_premium_minor'], 'ceded_premium_minor' => $summary['ceded_premium_minor'], 'net_premium_minor' => $summary['net_premium_minor']]);

            return $summary;
        });
    }

    /** Current (CALCULATED) cessions of a policy with gross / net exposure. */
    public function forPolicy(string $tenantId, string $policyId): array
    {
        $policy = $this->policy($tenantId, $policyId);
        $run = DB::table('reinsurance_cessions')->where('policy_id', $policyId)->where('status', 'CALCULATED')->max('run');
        $rows = $run ? $this->load($policyId, (int) $run) : [];
        $first = $rows[0] ?? null;
        $basis = ['currency' => $policy->currency, 'gross_premium_minor' => (int) ($first['gross_premium_minor'] ?? $policy->premium_minor),
            'sum_insured_minor' => $first['sum_insured_minor'] ?? $this->sumInsured($policy)];

        return $this->summary($policy, $basis, $rows, $run ? (int) $run : null);
    }

    private function inputs(string $tenantId, string $policyId, ?int $sumInsured, bool $lock = false): array
    {
        $policy = $this->policy($tenantId, $policyId, $lock);
        if (in_array($policy->status, ['DRAFT', 'PENDING_PAYMENT', 'QUOTED'], true)) {
            throw ValidationException::withMessages(['policy' => 'Only issued policies can be ceded.']);
        }
        $terms = json_decode((string) $policy->terms_snapshot, true) ?: [];
        $basis = ['currency' => $policy->currency, 'gross_premium_minor' => (int) $policy->premium_minor, 'sum_insured_minor' => $sumInsured ?? $this->sumInsured($policy)];
        $versions = $this->treaties->effectiveVersions($tenantId, substr((string) $policy->coverage_starts_at, 0, 10), $terms['line_code'] ?? null, $policy->currency);

        return [$policy, $basis, $versions];
    }

    private function policy(string $tenantId, string $policyId, bool $lock = false): object
    {
        // Read-only: shared lock at most; policies are owned by the issuance module.
        $q = DB::table('policies')->where('tenant_id', $tenantId)->where('id', $policyId);

        return ($lock ? $q->sharedLock() : $q)->first() ?? abort(404, 'Policy not found.');
    }

    private function sumInsured(object $policy): ?int
    {
        $terms = json_decode((string) $policy->terms_snapshot, true) ?: [];
        $v = $terms['sum_insured_minor'] ?? null;

        return is_numeric($v) ? (int) $v : null;
    }

    private function sameRun($current, object $policy, array $basis, array $cessions): bool
    {
        $first = $current->first();
        if ((int) $first->policy_version !== (int) $policy->version || (int) $first->gross_premium_minor !== $basis['gross_premium_minor']
            || ($first->sum_insured_minor === null ? null : (int) $first->sum_insured_minor) !== $basis['sum_insured_minor']) {
            return false;
        }
        $a = $current->pluck('treaty_version_id')->sort()->values()->all();
        $b = collect($cessions)->pluck('treaty_version_id')->sort()->values()->all();

        return $a === $b;
    }

    private function load(string $policyId, int $run): array
    {
        $order = ['QUOTA_SHARE' => 1, 'SURPLUS' => 2, 'EXCESS_OF_LOSS' => 3, 'STOP_LOSS' => 4];

        return DB::table('reinsurance_cessions')->where('policy_id', $policyId)->where('run', $run)->get()
            ->sortBy(fn ($c) => ($order[$c->treaty_type] ?? 9).$c->treaty_version_id)->values()->map(function ($c) {
            $a = (array) $c;
            $a['layers'] = json_decode($a['layers'], true);
            foreach (['sum_insured_minor', 'subject_sum_minor', 'ceded_sum_minor'] as $k) {
                $a[$k] = $a[$k] === null ? null : (int) $a[$k];
            }
            foreach (['gross_premium_minor', 'ceded_premium_minor', 'commission_minor', 'brokerage_minor', 'tax_minor', 'net_ceded_premium_minor'] as $k) {
                $a[$k] = (int) $a[$k];
            }
            $a['ceded_percent'] = (float) $a['ceded_percent'];
            $a['shares'] = DB::table('reinsurance_cession_shares')->where('cession_id', $c->id)->get()->map(fn ($s) => (array) $s)->all();

            return $a;
        })->all();
    }

    private function summary(object $policy, array $basis, array $cessions, ?int $run, bool $replayed = false): array
    {
        $net = CessionCalculator::net($basis['sum_insured_minor'], $basis['gross_premium_minor'], $cessions);
        $sum = fn (string $k) => array_sum(array_column($cessions, $k));

        return ['policy_id' => $policy->id, 'run' => $run, 'replayed' => $replayed, 'currency' => $basis['currency'],
            'gross_sum_insured_minor' => $basis['sum_insured_minor'], 'gross_premium_minor' => $basis['gross_premium_minor'],
            'ceded_premium_minor' => $sum('ceded_premium_minor'), 'commission_minor' => $sum('commission_minor'), 'brokerage_minor' => $sum('brokerage_minor'),
            'tax_minor' => $sum('tax_minor'), 'net_ceded_premium_minor' => $sum('net_ceded_premium_minor'),
            'net_sum_insured_minor' => $net['net_sum_minor'], 'net_premium_minor' => $net['net_premium_minor'], 'cessions' => $cessions];
    }
}
