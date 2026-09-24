<?php

declare(strict_types=1);

namespace App\Application\Catalogue;

/**
 * The quote wizard's question schema per insurance line: fields
 * {key,label,type,options,required,step} grouped into ordered steps.
 * PlatformCatalogueSeeder writes these onto insurance_lines.risk_schema
 * (alongside the rating "required" keys); GET
 * /mobile/catalogue/lines/{code}/risk-schema falls back to these defaults
 * for a line whose stored schema has no fields yet.
 *
 * Keys are the facts the tariffs rate on (DeterministicRatingEngine
 * factors / required_facts): numbers are numbers, selects are the exact
 * uppercase codes the factors compare against, booleans are booleans.
 */
final class RiskSchemaCatalogue
{
    /** @return array<string, array{steps: array<int, array{key:string,label:string}>, fields: array<int, array<string, mixed>>, required: array<int, string>}> */
    public static function all(): array
    {
        return [
            // MOTOR is owned by the vehicle master data (make/model selectors, 28 usages, EV/commercial fields).
            'MOTOR' => \App\Application\Vehicles\MotorRiskSchema::schema(),
            // Non-motor lines: institutional master data (SELECT_MASTER fields), see NonMotorRiskSchemas.
        ] + NonMotorRiskSchemas::all();
    }

    /** @return array<string, mixed>|null */
    public static function for(string $lineCode): ?array
    {
        return self::all()[strtoupper($lineCode)] ?? null;
    }

    private static function schema(array $steps, array $fields, array $required): array
    {
        return ['steps' => array_map(fn ($s) => ['key' => $s[0], 'label' => $s[1]], $steps), 'fields' => $fields, 'required' => $required];
    }

    private static function f(string $key, string $label, string $type, string $step, bool $required, ?array $options = null, array $extra = []): array
    {
        return [
            'key' => $key, 'label' => $label, 'type' => $type, 'step' => $step, 'required' => $required,
            'options' => $options ? array_map(fn ($o) => ['value' => $o[0], 'label' => $o[1]], $options) : null,
        ] + $extra;
    }
}
