<?php

declare(strict_types=1);

namespace App\Application\Risks\Http;

use App\Application\Identity\OwnershipScope;
use App\Application\Risks\RiskAssetTypes;
use App\Domain\Tenancy\TenantContext;
use App\Models\RiskAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * REQ-RSK-001: insured-object type catalogue and version history. Row access
 * mirrors RiskAssetController (tenant + OwnershipScope: a customer only
 * resolves their own assets; a foreign one is a 404).
 */
final class RiskAssetVersionController
{
    public function __construct(private readonly OwnershipScope $own, private readonly TenantContext $context) {}

    public function types(): JsonResponse
    {
        return response()->json(['data' => RiskAssetTypes::describe()]);
    }

    public function index(Request $r, string $riskAsset): JsonResponse
    {
        $asset = $this->asset($r, $riskAsset);
        $rows = DB::table('risk_asset_versions')->where('risk_asset_id', $asset->id)->orderByDesc('version')
            ->get(['version', 'type', 'display_name', 'external_reference', 'status', 'facts_hash', 'recorded_by', 'recorded_at']);

        return response()->json(['data' => ['risk_asset_id' => $asset->id, 'current_version' => $asset->version, 'versions' => $rows]]);
    }

    public function show(Request $r, string $riskAsset, int $version): JsonResponse
    {
        $asset = $this->asset($r, $riskAsset);
        $row = DB::table('risk_asset_versions')->where('risk_asset_id', $asset->id)->where('version', $version)->first();
        abort_if(! $row, 404, __('risks.version_not_found'));
        $row->facts = json_decode((string) $row->facts, true);
        $row->current_vehicle_record = $asset->type && RiskAssetTypes::isVehicle($asset->type)
            ? DB::table('risk_asset_vehicles')->where('risk_asset_id', $asset->id)->first(['make_id', 'model_id', 'generation_id', 'variant_id', 'make_text', 'model_text', 'reconciliation_status', 'registration_number', 'vin'])
            : null;

        return response()->json(['data' => $row]);
    }

    private function asset(Request $r, string $id): RiskAsset
    {
        return $this->own->apply(RiskAsset::where('tenant_id', $this->context->id()), $r->user())->findOrFail($id);
    }
}
