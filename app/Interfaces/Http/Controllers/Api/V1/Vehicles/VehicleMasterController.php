<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Vehicles;

use App\Application\Identity\PartyResolver;
use App\Application\Vehicles\VehicleCatalogueService;
use App\Application\Vehicles\VehicleMasterReviewService;
use App\Models\RiskAsset;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleReferenceValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * GET  /api/v1/public/vehicles/makes?q=&segment=&chinese=1&limit=
 *      { data: [{code,name,country_of_origin,segment,cameroon_status,market_priority,aliases[],models_count}], meta: {total} }
 * GET  /api/v1/public/vehicles/makes/{code}/models?q=
 *      { make: {code,name}, data: [{code,name,segment,status,aliases[]}] }
 * GET  /api/v1/public/vehicles/reference
 *      { data: { body_types:[{code,label:{en,fr}}], usage_types, …, model_years:{min,max} } }
 * POST /api/v1/mobile/vehicles/master-review   (auth)
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

    public function reference(VehicleCatalogueService $catalogue): JsonResponse
    {
        return response()->json(['data' => $catalogue->reference()])->header('Cache-Control', 'public, max-age=600');
    }

    public function submitReview(Request $request, VehicleMasterReviewService $reviews, PartyResolver $parties): JsonResponse
    {
        $range = VehicleCatalogueService::modelYearRange();
        $codes = fn (string $group) => Rule::in(VehicleReferenceValue::where('group', $group)->pluck('code')->all());
        $data = $request->validate([
            'make' => 'required|string|min:1|max:120',
            'model' => 'required|string|min:1|max:120',
            'model_year' => "nullable|integer|between:{$range['min']},{$range['max']}",
            'body_type' => ['nullable', 'string', $codes('body_type')],
            'powertrain' => ['nullable', 'string', $codes('powertrain')],
            'usage' => ['nullable', 'string', $codes('usage')],
            'vin' => 'nullable|string|max:40',
            'registration_number' => 'nullable|string|max:40',
            'engine_number' => 'nullable|string|max:60',
            'risk_asset_id' => 'nullable|uuid',
        ]);

        $tenantId = null;
        if (! empty($data['risk_asset_id'])) {
            $party = $parties->forUser($request->user());
            $asset = $party ? RiskAsset::where('id', $data['risk_asset_id'])->where('party_id', $party->id)->first() : null;
            if (! $asset) {
                throw ValidationException::withMessages(['risk_asset_id' => 'Unknown vehicle.']);
            }
            $tenantId = $asset->tenant_id;
        }

        $review = $reviews->submit($data, $request->user(), $tenantId);

        return response()->json(['data' => [
            'id' => $review->id,
            'status' => $review->status,
            'make' => $review->make_text,
            'model' => $review->model_text,
            'model_year' => $review->model_year,
        ]], 201);
    }
}
