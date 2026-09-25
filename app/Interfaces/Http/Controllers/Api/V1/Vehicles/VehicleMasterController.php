<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Vehicles;

use App\Application\Vehicles\VehicleCatalogueService;
use App\Application\Vehicles\VehicleSuggestionIntake;
use App\Models\Vehicles\VehicleGeneration;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleModel;
use Database\Seeders\VehicleMasterDataSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET  /api/v1/public/vehicles/makes?q=&segment=&chinese=1&limit=
 *      { data: [{code,name,country_of_origin,segment,cameroon_status,market_priority,aliases[],models_count}], meta: {total} }
 * GET  /api/v1/public/vehicles/makes/{code}/models?q=
 *      { make: {code,name}, data: [{code,name,segment,status,aliases[]}] }
 * GET  /api/v1/public/vehicles/reference
 *      { data: { body_types:[{code,label:{en,fr}}], usage_types, …, model_years:{min,max} } }
 * GET  /api/v1/public/vehicles/models/{model}/generations                    (CUST-007)
 *      { model: {code,name,make}, data: [{code,name,year_from,year_to,variants_count}] }
 * GET  /api/v1/public/vehicles/models/{model}/generations/{generation}/variants
 *      { generation: {code,name}, data: [{code,name,body_type,powertrain,hybrid_subtype,transmission,drive_type,engine_capacity_cc,year_from,year_to}] }
 *      ?year= filters to variants covering that model year. specs = auto-populate block
 *      {power_hp, power_kw, displacement_cc, fuel_type, transmission, drivetrain, body_type, torque_nm}.
 * GET  /api/v1/public/vehicles/models/{model}/generations/{generation}/years  { data: [2025, 2024, ...] }
 * GET  /api/v1/public/vehicles/config   selection flow, auto-populate fields, manual-entry policy, fallback
 *      Generations/variants start empty: only admins, the dataset import or approved reviews add them.
 * POST /api/v1/mobile/vehicles/master-review   (auth) DEPRECATED alias (REQ-DUP-013):
 *      canonical is POST /api/v1/master-data/suggestions {domain:"vehicle", list:"models", text:<model>, parent:<make>, attributes:{…}}
 *      { make, model, model_year?, body_type?, vin?, registration_number?, engine_number?, powertrain?, usage?, risk_asset_id? }
 *      → 201 { data: {id,status:"MASTER_DATA_REVIEW_REQUIRED",make,model} }
 */
final class VehicleMasterController
{
    public function makes(Request $request, VehicleCatalogueService $catalogue): JsonResponse
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:60',
            'segment' => 'nullable|in:PASSENGER,COMMERCIAL,MIXED,passenger,commercial,mixed',
            'chinese' => 'nullable|in:0,1,true,false',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $result = $catalogue->searchMakes($data['q'] ?? null, $data['segment'] ?? null, in_array($data['chinese'] ?? '0', ['1', 'true'], true), (int) ($data['limit'] ?? 30));

        return response()->json([
            'data' => $result['items']->map(fn ($m) => $catalogue->presentMake($m))->all(),
            'meta' => ['total' => $result['total']],
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function models(string $code, Request $request, VehicleCatalogueService $catalogue): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:60']);
        $make = VehicleMake::where('code', strtoupper($code))->where('active', true)->first();
        abort_if(! $make, 404, 'Unknown vehicle make.');

        return response()->json([
            'make' => ['code' => $make->code, 'name' => $make->name],
            'data' => $catalogue->modelsFor($make, $data['q'] ?? null)->map(fn ($m) => $catalogue->presentModel($m))->all(),
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function generations(string $model, VehicleCatalogueService $catalogue): JsonResponse
    {
        $row = $this->activeModel($model);

        return response()->json([
            'model' => ['code' => $row->code, 'name' => $row->name, 'make' => $row->make?->code],
            'data' => $catalogue->generationsFor($row)->map(fn ($g) => $catalogue->presentGeneration($g))->all(),
            // Owner workflow data master: generation/variant catalogue is PENDING_SOURCE (empty is expected; Other / Not listed works).
            'meta' => app(\App\Application\MasterData\WorkflowDataStatuses::class)->pickerMeta('vehicles.detailed_generation_variant'),
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function years(string $model, string $generation, VehicleCatalogueService $catalogue): JsonResponse
    {
        $gen = $this->activeGeneration($this->activeModel($model), $generation);

        return response()->json([
            'generation' => ['code' => $gen->code, 'name' => $gen->name, 'year_from' => $gen->year_from, 'year_to' => $gen->year_to],
            'data' => $catalogue->yearsFor($gen),
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function variants(string $model, string $generation, Request $request, VehicleCatalogueService $catalogue): JsonResponse
    {
        $range = VehicleCatalogueService::modelYearRange();
        $q = $request->validate(['year' => "nullable|integer|between:{$range['min']},{$range['max']}"]);
        $gen = $this->activeGeneration($this->activeModel($model), $generation);

        return response()->json([
            'generation' => ['code' => $gen->code, 'name' => $gen->name, 'year_from' => $gen->year_from, 'year_to' => $gen->year_to],
            'data' => $catalogue->variantsFor($gen, isset($q['year']) ? (int) $q['year'] : null)->each->setRelation('generation', $gen)
                ->map(fn ($v) => $catalogue->presentVariant($v))->values()->all(),
            'meta' => app(\App\Application\MasterData\WorkflowDataStatuses::class)->pickerMeta('vehicles.detailed_generation_variant'),
        ])->header('Cache-Control', 'public, max-age=300');
    }

    /** Picker configuration from vehicle_master_config_africa_2026.json (flow, auto-populated fields, manual entry policy). */
    public function config(): JsonResponse
    {
        $path = database_path(VehicleMasterDataSeeder::AFRICA_CONFIG_FILE);
        $c = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $cat = $c['catalogue'] ?? [];

        return response()->json(['data' => [
            'version' => $cat['version'] ?? null,
            'selection_flow' => $cat['selection_flow'] ?? [],
            'auto_populate_from_engine_variant' => $cat['auto_populate_from_engine_variant'] ?? [],
            'manual_entry_policy' => $cat['manual_entry_policy'] ?? null,
            'source_priority' => $cat['source_priority'] ?? [],
            'fallback' => ($c['fallback'] ?? []) + ['endpoint' => '/api/v1/master-data/suggestions', 'domain' => 'vehicle'],
        ]])->header('Cache-Control', 'public, max-age=600');
    }

    private function activeGeneration(VehicleModel $model, string $code): VehicleGeneration
    {
        $gen = VehicleGeneration::where('model_id', $model->id)->where('code', strtoupper($code))->where('active', true)->first();
        abort_if(! $gen, 404, 'Unknown vehicle generation.');

        return $gen;
    }

    private function activeModel(string $code): VehicleModel
    {
        $model = VehicleModel::with('make')->where('code', strtoupper($code))->where('active', true)->first();
        abort_if(! $model, 404, 'Unknown vehicle model.');

        return $model;
    }

    public function reference(VehicleCatalogueService $catalogue): JsonResponse
    {
        return response()->json(['data' => $catalogue->reference()])->header('Cache-Control', 'public, max-age=600');
    }

    /** Deprecated alias (REQ-DUP-013); same queue as master-data/suggestions domain "vehicle". */
    public function submitReview(Request $request, VehicleSuggestionIntake $intake): JsonResponse
    {
        $data = $request->validate([
            'make' => 'required|string|min:1|max:120',
            'model' => 'required|string|min:1|max:120',
        ] + VehicleSuggestionIntake::attributeRules());

        $review = $intake->submit($data, $request->user());

        return response()->json(['data' => [
            'id' => $review->id,
            'status' => $review->status,
            'make' => $review->make_text,
            'model' => $review->model_text,
            'model_year' => $review->model_year,
        ]], 201);
    }
}
