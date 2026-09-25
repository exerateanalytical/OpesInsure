<?php

declare(strict_types=1);

namespace App\Application\Risks;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\MasterData\RiskFactsProcessor;
use App\Application\Shared\CanonicalJson;
use App\Models\Party;
use App\Models\RiskAsset;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-RSK-001: the one writer for insured objects (risk_assets). Every write
 * bumps `version`; RiskAssetVersionRecorder snapshots each version into
 * risk_asset_versions, risk_asset_events keeps the change log. Before a
 * write the facts are checked against master data (RiskFactsProcessor for
 * the type's risk schema) and a vehicle is checked for a duplicate VIN /
 * plate within the tenant (WF-077).
 */
final class RiskAssetService
{
    public function __construct(
        private readonly CanonicalJson $json,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly RiskFactsProcessor $facts,
        private readonly VehicleDuplicateGuard $duplicates,
    ) {}

    public function create(Tenant $t, Party $p, array $data, ?User $actor): RiskAsset
    {
        return DB::transaction(function () use ($t, $p, $data, $actor) {
            if (! $p->customers()->where('tenant_id', $t->id)->exists()) {
                throw ValidationException::withMessages(['party_id' => __('wave2.party_not_customer')]);
            }
            $this->checkFacts($t->id, (string) $data['type'], (array) $data['facts'], null, $actor);
            $asset = RiskAsset::create([...$data, 'tenant_id' => $t->id, 'party_id' => $p->id, 'facts_hash' => $this->json->hash($data['facts']), 'status' => 'ACTIVE', 'version' => 1]);
            $this->event($asset, null, 1, 'CREATED', array_keys($data), $actor);
            $this->audit->record('risk_asset.created', 'risk_asset', $asset->id, ['type' => $asset->type]);
            $this->outbox->record('risk_asset.created', 'risk_asset', $asset->id, ['risk_asset_id' => $asset->id, 'tenant_id' => $t->id]);

            return $asset;
        });
    }

    public function update(RiskAsset $a, int $expected, array $changes, ?User $actor): RiskAsset
    {
        return DB::transaction(function () use ($a, $expected, $changes, $actor) {
            $locked = RiskAsset::whereKey($a->id)->lockForUpdate()->firstOrFail();
            if ($locked->version !== $expected) {
                throw ValidationException::withMessages(['version' => __('wave2.stale_asset')]);
            }
            if (isset($changes['facts'])) {
                $this->checkFacts($locked->tenant_id, $locked->type, (array) $changes['facts'], $locked->id, $actor);
            }
            $from = $locked->version;
            $update = $changes;
            if (isset($changes['facts'])) {
                $update['facts_hash'] = $this->json->hash($changes['facts']);
            }
            $update['version'] = $from + 1;
            $locked->update($update);
            $this->event($locked, $from, $from + 1, 'UPDATED', array_keys($changes), $actor);
            $this->audit->record('risk_asset.updated', 'risk_asset', $locked->id, ['version' => $from + 1, 'changed_fields' => array_keys($changes)]);
            $this->outbox->record('risk_asset.updated', 'risk_asset', $locked->id, ['risk_asset_id' => $locked->id, 'version' => $from + 1]);

            return $locked->refresh();
        });
    }

    private function checkFacts(string $tenantId, string $type, array $facts, ?string $selfId, ?User $actor): void
    {
        if (RiskAssetTypes::isVehicle($type)) {
            $this->duplicates->assertUnique($tenantId, $facts, $selfId);

            return;
        }
        $line = RiskAssetTypes::lineFor($type);
        if ($line !== null) {
            // Validates master-data codes only; the stored facts stay exactly what was submitted.
            $this->facts->process($line, $facts, $tenantId, $actor?->id);
        }
    }

    private function event(RiskAsset $a, ?int $from, int $to, string $type, array $fields, ?User $actor): void
    {
        DB::table('risk_asset_events')->insert(['id' => (string) Str::uuid(), 'risk_asset_id' => $a->id, 'from_version' => $from, 'to_version' => $to, 'type' => $type, 'changed_fields' => json_encode($fields), 'facts_hash' => $a->facts_hash, 'actor_id' => $actor?->id, 'occurred_at' => now()]);
    }
}
