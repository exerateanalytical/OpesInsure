<?php

declare(strict_types=1);

namespace App\Application\Catalogue;

use App\Application\MasterData\InputFieldContract;

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
        $all = [
            // MOTOR is owned by the vehicle master data (make/model selectors, 28 usages, EV/commercial fields).
            'MOTOR' => self::bindMotorFreeText(\App\Application\Vehicles\MotorRiskSchema::schema()),
            // Non-motor lines: institutional master data (SELECT_MASTER fields), see NonMotorRiskSchemas.
        ] + NonMotorRiskSchemas::all();

        // Selection-first input contract (source/allow_other/input/free_text) — see InputFieldContract.
        return array_map([InputFieldContract::class, 'annotate'], $all);
    }

    /**
     * MOTOR free-text fields bound to master data without editing the vehicle
     * master's schema (docs/audit/FREE_TEXT_FIELDS_AUDIT.md):
     *   cargo_type       text -> cargo.cargo_category (Other -> review queue)
     *   previous_insurer text -> official insurer register (public/institutions?type=insurer)
     */
    private static function bindMotorFreeText(array $schema): array
    {
        // v2 so a stored MOTOR wizard seeded before the binding is served the bound fields.
        $schema['version'] = max(2, (int) ($schema['version'] ?? 1));
        foreach ($schema['fields'] as &$f) {
            if ($f['key'] === 'cargo_type' && ($f['type'] ?? '') === 'text') {
                $f = ['type' => 'select_master', 'label_fr' => 'Marchandises transportées', 'source' => ['domain' => 'cargo', 'list' => 'cargo_category'], 'other_allowed' => true] + $f;
            } elseif ($f['key'] === 'previous_insurer' && ($f['type'] ?? '') === 'text') {
                $f = ['type' => 'select_master', 'label_fr' => 'Assureur précédent', 'source' => ['domain' => 'institutions', 'list' => 'insurer'], 'endpoint' => '/api/v1/public/institutions?type=insurer', 'other_allowed' => true] + $f;
            }
        }
        unset($f);

        return $schema;
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
