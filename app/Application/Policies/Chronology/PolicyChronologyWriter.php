<?php

namespace App\Application\Policies\Chronology;

use App\Application\Shared\CanonicalJson;
use App\Models\Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-POL-002 — writes one bitemporal policy_versions row plus its structured
 * parties / risks / coverages / limits. The previous open version is closed
 * (valid_to = new valid_from, superseded_at = now). Idempotent per version_no.
 */
final class PolicyChronologyWriter
{
    public const KINDS = ['ISSUANCE', 'ENDORSEMENT', 'RENEWAL', 'REINSTATEMENT', 'CANCELLATION', 'BACKFILL', 'SUSPENSION'];

    public function __construct(private IssuanceSnapshotBuilder $builder, private CanonicalJson $json) {}

    public function record(Policy $policy, string $kind, ?\DateTimeInterface $validFrom = null, array $options = []): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown policy version kind {$kind}.");
        }

        return DB::transaction(function () use ($policy, $kind, $validFrom, $options): string {
            $versionNo = (int) ($options['version_no'] ?? $policy->version ?? 1);
            $existing = DB::table('policy_versions')->where('policy_id', $policy->id)->where('version_no', $versionNo)->value('id');
            if ($existing) {
                return $existing;
            }

            $validFrom ??= $policy->coverage_starts_at ?? now();
            $now = now();
            $snapshot = $this->builder->build($policy, $versionNo, $options['terms'] ?? null, $options['authority'] ?? null);

            DB::table('policy_versions')->where('policy_id', $policy->id)->whereNull('superseded_at')->lockForUpdate()->get()
                ->each(function ($v) use ($validFrom, $now) {
                    DB::table('policy_versions')->where('id', $v->id)->update([
                        'valid_to' => $v->valid_to ?? \Carbon\CarbonImmutable::parse($v->valid_from)->max(\Carbon\CarbonImmutable::instance($validFrom)),
                        'superseded_at' => $now,
                        'updated_at' => $now,
                    ]);
                });

            $versionId = (string) Str::uuid();
            DB::table('policy_versions')->insert([
                'id' => $versionId, 'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id,
                'version_no' => $versionNo, 'kind' => $kind,
                'valid_from' => $validFrom, 'valid_to' => null, 'recorded_at' => $now, 'superseded_at' => null,
                'schema_version' => IssuanceSnapshotBuilder::SCHEMA_VERSION,
                'snapshot' => json_encode($snapshot), 'snapshot_hash' => $this->json->hash($snapshot),
                'terms_hash' => $policy->terms_hash,
                'source_type' => $options['source_type'] ?? null, 'source_id' => $options['source_id'] ?? null,
                'recorded_by' => $options['actor_id'] ?? null,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $base = ['policy_id' => $policy->id, 'policy_version_id' => $versionId, 'created_at' => $now, 'updated_at' => $now];
            $parties = [['role' => 'POLICYHOLDER', 'party_id' => $policy->party_id, 'display_name' => null, 'allocation_bp' => null]];
            foreach ($snapshot['beneficiaries'] as $b) {
                $parties[] = ['role' => 'BENEFICIARY_'.$b['designation'], 'party_id' => $b['party_id'], 'display_name' => $b['full_name'], 'allocation_bp' => $b['allocation_bp']];
            }
            foreach ($parties as $p) {
                DB::table('policy_parties')->insert($base + $p + ['id' => (string) Str::uuid(), 'attributes' => '{}']);
            }
            foreach ($snapshot['risk'] as $r) {
                DB::table('policy_risks')->insert($base + [
                    'id' => (string) Str::uuid(), 'risk_type' => substr($r['risk_type'], 0, 32), 'risk_asset_id' => $r['risk_asset_id'],
                    'display_name' => $r['display_name'], 'facts' => json_encode((object) $r['facts']), 'facts_hash' => $this->json->hash($r['facts']),
                ]);
            }
            $currency = $snapshot['premium']['currency'];
            $ends = $policy->coverage_ends_at ?? $validFrom;
            foreach ($snapshot['coverages'] as $c) {
                $coverageId = (string) Str::uuid();
                DB::table('policy_coverages')->insert($base + [
                    'id' => $coverageId, 'coverage_code' => $c['code'], 'name' => json_encode($c['name']),
                    'mandatory' => $c['mandatory'], 'optional' => $c['optional'],
                    'limit_minor' => $c['limit_minor'], 'deductible_minor' => $c['deductible_minor'], 'premium_minor' => $c['premium_minor'],
                    'currency' => $currency, 'starts_at' => $validFrom, 'ends_at' => \Carbon\CarbonImmutable::instance($ends)->max(\Carbon\CarbonImmutable::instance($validFrom)),
                ]);
                foreach (['PER_CLAIM' => $c['limit_minor'], 'DEDUCTIBLE' => $c['deductible_minor']] as $type => $amount) {
                    if ($amount !== null) {
                        DB::table('policy_limits')->insert($base + ['id' => (string) Str::uuid(), 'policy_coverage_id' => $coverageId, 'limit_type' => $type, 'amount_minor' => $amount, 'currency' => $currency]);
                    }
                }
            }

            return $versionId;
        });
    }

    /** Version effective at a business instant, as known at a system instant (bitemporal read). */
    public function asOf(string $policyId, \DateTimeInterface $validAt, ?\DateTimeInterface $knownAt = null): ?object
    {
        $knownAt ??= now();

        return DB::table('policy_versions')->where('policy_id', $policyId)
            ->where('valid_from', '<=', $validAt)->where('recorded_at', '<=', $knownAt)
            // valid_to only became known at superseded_at: before that the row was open-ended.
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $validAt)->orWhere('superseded_at', '>', $knownAt))
            ->orderByDesc('version_no')->first();
    }
}
