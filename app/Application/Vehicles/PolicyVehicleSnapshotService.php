<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

use App\Models\Policy;
use App\Models\Vehicles\PolicyVehicleSnapshot;
use App\Models\Vehicles\RiskAssetVehicle;
use App\Models\Vehicles\VehicleGeneration;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleVariant;
use Illuminate\Support\Facades\DB;

/**
 * Captures the insured vehicle once, when a policy is issued
 * (config storage_policy.policy_issue_snapshot_required). The policy's risk
 * asset is found via proposal -> quote offer -> quote. Non-vehicle policies
 * get no snapshot.
 */
final class PolicyVehicleSnapshotService
{
    public function capture(Policy $policy): ?PolicyVehicleSnapshot
    {
        if ($existing = PolicyVehicleSnapshot::where('policy_id', $policy->id)->first()) {
            return $existing;
        }
        $quote = $policy->proposal_id ? DB::table('proposals')
            ->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')
            ->join('quotes', 'quotes.id', '=', 'quote_offers.quote_id')
            ->where('proposals.id', $policy->proposal_id)->first(['quotes.risk_asset_id', 'quotes.risk_facts', 'quote_offers.rating_run_id']) : null;
        $riskAssetId = $quote?->risk_asset_id;
        $quoteFacts = $quote && $quote->risk_facts ? (array) json_decode((string) $quote->risk_facts, true) : [];
        $record = $riskAssetId ? RiskAssetVehicle::where('risk_asset_id', $riskAssetId)->first() : null;
        if (! $record) {
            return null;
        }

        $make = $record->make_id ? VehicleMake::find($record->make_id) : null;
        $model = $record->model_id ? VehicleModel::find($record->model_id) : null;
        $generation = $record->generation_id ? VehicleGeneration::find($record->generation_id) : null;
        $variant = $record->variant_id ? VehicleVariant::find($record->variant_id) : null;

        $snapshot = [
            'make' => $make ? ['code' => $make->code, 'name' => $make->name, 'data_source' => $make->data_source] : null,
            'model' => $model ? ['code' => $model->code, 'name' => $model->name, 'data_source' => $model->data_source] : null,
            'generation' => $generation ? $generation->only(['code', 'name', 'year_from', 'year_to', 'body_type', 'data_source']) : null,
            'variant' => $variant ? $variant->only(['code', 'name', 'power_hp', 'power_kw', 'torque_nm', 'engine_capacity_cc', 'powertrain', 'hybrid_subtype', 'transmission', 'drive_type', 'body_type', 'cylinders', 'data_source']) : null,
            'typed' => ['make' => $record->make_text, 'model' => $record->model_text],
            'vehicle' => collect($record->getAttributes())->except(['id', 'risk_asset_id', 'make_id', 'model_id', 'generation_id', 'variant_id', 'review_id', 'created_at', 'updated_at'])->all(),
            'reconciliation_status' => $record->reconciliation_status,
            // Picker facts as quoted (codes + stated specs).
            // Vehicle Power master: fiscal power / band / schedule / rate used at rating, frozen with the policy.
            'fiscal_power' => $quote?->rating_run_id ? json_decode((string) DB::table('rating_runs')->where('id', $quote->rating_run_id)->value('fiscal_power_snapshot'), true) : null,
            'quoted' => array_intersect_key($quoteFacts, array_flip(['make_code', 'model_code', 'vehicle_generation_code', 'vehicle_variant_code', 'year', 'engine_capacity_cc', 'power_hp', 'drive_type', 'powertrain', 'transmission', 'body_type'])),
        ];

        return PolicyVehicleSnapshot::create([
            'policy_id' => $policy->id, 'risk_asset_id' => $riskAssetId,
            'make_id' => $make?->id, 'model_id' => $model?->id, 'generation_id' => $generation?->id, 'variant_id' => $variant?->id,
            'spec_snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', (string) json_encode($snapshot)), 'captured_at' => now(),
        ]);
    }
}
