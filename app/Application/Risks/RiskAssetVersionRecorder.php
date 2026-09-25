<?php

declare(strict_types=1);

namespace App\Application\Risks;

use App\Models\RiskAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-RSK-001: freezes each version of an insured object. Hooked on
 * RiskAsset::saved (RiskSearchServiceProvider) so every writer is covered,
 * including direct model writes. A version is written once and never
 * rewritten (ON CONFLICT DO NOTHING) — RiskAssetService bumps `version`
 * on every change.
 */
final class RiskAssetVersionRecorder
{
    public function record(RiskAsset $asset): void
    {
        DB::table('risk_asset_versions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'risk_asset_id' => $asset->id,
            'tenant_id' => $asset->tenant_id,
            'version' => (int) $asset->version,
            'type' => $asset->type,
            'display_name' => $asset->display_name,
            'external_reference' => $asset->external_reference,
            'status' => $asset->status ?? 'ACTIVE',
            'facts' => json_encode($asset->facts ?? []),
            'facts_hash' => (string) $asset->facts_hash,
            'recorded_by' => request()->user()?->getKey() ?? auth()->id(),
            'recorded_at' => now(),
        ]);
    }
}
