<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Catalogue;

use App\Application\Catalogue\RiskSchemaCatalogue;
use App\Application\MasterData\MasterDataFlows;
use App\Models\InsuranceLine;
use Illuminate\Http\JsonResponse;

/**
 * GET /mobile/catalogue/lines/{code}/risk-schema
 * { data: { line_code, name, version, steps:[{key,label,label_en,label_fr}], fields:[{key,label,label_en,label_fr,type,required,step,
 *   options?, source?:{domain,list}, parent_field?, other_allowed?, visible_if?, item_fields?, allocation?, min?, max?, pattern?}], required:[...] } }
 *
 * Catalogue schemas carry a `version`; a stored line schema older than the
 * catalogue (seeded before master data existed) is replaced by the catalogue
 * fields while the stored rating `required` keys are kept. Lines without a
 * catalogue schema fall back to master-data flows (specialty domains).
 */
final class MobileRiskSchemaController
{
    public function __construct(private readonly MasterDataFlows $flows) {}

    public function __invoke(string $code): JsonResponse
    {
        $code = strtoupper($code);
        $line = InsuranceLine::where('code', $code)->where('status', 'ACTIVE')->first();
        $stored = $line?->risk_schema ?? [];
        $defaults = RiskSchemaCatalogue::for($code) ?? $this->flows->schemaFor($code);

        if (! $line && ! $defaults) {
            abort(404, 'Unknown insurance line.');
        }

        $storedIsCurrent = ! empty($stored['fields']) && (int) ($stored['version'] ?? 1) >= (int) ($defaults['version'] ?? 1);
        $schema = $storedIsCurrent ? $stored : array_merge($defaults ?? [], array_filter(['required' => $stored['required'] ?? null]));

        return response()->json(['data' => [
            'line_code' => $code,
            'name' => $line?->name,
            'version' => (int) ($schema['version'] ?? 1),
            'steps' => $schema['steps'] ?? [],
            'fields' => $schema['fields'] ?? [],
            'required' => array_values($schema['required'] ?? []),
        ]]);
    }
}
