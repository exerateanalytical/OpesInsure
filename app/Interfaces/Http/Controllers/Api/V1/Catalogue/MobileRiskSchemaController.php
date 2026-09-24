<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Catalogue;

use App\Application\Catalogue\RiskSchemaCatalogue;
use App\Models\InsuranceLine;
use Illuminate\Http\JsonResponse;

/**
 * GET /mobile/catalogue/lines/{code}/risk-schema
 * { data: { line_code, name, steps:[{key,label}], fields:[{key,label,type,options,required,step,...}], required:[...] } }
 */
final class MobileRiskSchemaController
{
    public function __invoke(string $code): JsonResponse
    {
        $code = strtoupper($code);
        $line = InsuranceLine::where('code', $code)->where('status', 'ACTIVE')->first();
        $stored = $line?->risk_schema ?? [];
        $defaults = RiskSchemaCatalogue::for($code);

        if (! $line && ! $defaults) {
            abort(404, 'Unknown insurance line.');
        }

        $schema = ! empty($stored['fields']) ? $stored : array_merge($defaults ?? [], array_filter(['required' => $stored['required'] ?? null]));

        return response()->json(['data' => [
            'line_code' => $code,
            'name' => $line?->name,
            'steps' => $schema['steps'] ?? [],
            'fields' => $schema['fields'] ?? [],
            'required' => array_values($schema['required'] ?? []),
        ]]);
    }
}
