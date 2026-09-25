<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owner decisions 2026-09-25 items 1-6: CIMA mapping is coverage-level (PRODUCT -> COVERAGE -> CIMA BRANCH,
 * many-to-many). The owner's Q1 answers are stored as regulatory_class_defaults rows with
 * mapping_level = COVERAGE; nothing beyond Q1 is mapped (unlisted coverages, e.g. travel baggage, stay unmapped).
 * Idempotent: called by the migration (existing databases) and the CIMA dictionary seeder (fresh databases).
 */
final class CimaCoverageRules
{
    public const SOURCE = 'Owner decisions 2026-09-25 items 1-6 (Q1 coverage-level CIMA mapping)';

    /** [line_code, coverage_code, branch number, relationship, separate premium]. */
    public const RULES = [
        ['TRAVEL', 'MEDICAL', 2, 'PRIMARY', false],               // item 2: travel medical expenses -> 2
        ['TRAVEL', 'PERSONAL_ACCIDENT', 1, 'PRIMARY', false],     // item 2: personal accident -> 1
        ['TRAVEL', 'REPATRIATION', 18, 'PRIMARY', false],         // item 2: assistance -> 18
        ['TRAVEL', 'ASSISTANCE', 18, 'PRIMARY', false],
        ['TRAVEL', 'CANCELLATION', 16, 'PRIMARY', false],         // item 2: cancellation / financial loss -> 16
        ['HOME', 'FIRE', 8, 'PRIMARY', false],                    // item 4: property/fire -> 8 and/or 9
        ['HOME', 'WATER_DAMAGE', 9, 'PRIMARY', false],
        ['HOME', 'THEFT', 9, 'PRIMARY', false],
        ['HOME', 'LIABILITY', 13, 'PRIMARY', false],              // item 4: household liability -> 13
        ['BUSINESS', 'BUSINESS_INTERRUPTION', 16, 'PRIMARY', false], // item 5
        ['MOTOR', 'THEFT_FIRE', 3, 'PRIMARY', false],             // item 3: motor theft/fire stay 3
        ['LIFE', 'DISABILITY', 20, 'COMPLEMENTARY', true],        // item 6: complementary on the life branch
    ];

    /** Lines whose wholesale product-level rule is replaced by coverage rules. */
    public const REPLACED_PRODUCT_LEVEL = ['TRAVEL'];

    /** @return int rules created */
    public static function seed(): int
    {
        $version = DB::table('insurance_branches')->where('regime', 'CIMA')->where('is_seeded', true)->max('regulatory_version');
        if ($version === null) {
            return 0;
        }
        $codes = DB::table('insurance_branches')->where('regime', 'CIMA')->where('regulatory_version', $version)->pluck('code', 'number');
        // Existing coverage-conditional defaults (e.g. MOTOR OWN_DAMAGE -> 3) are coverage-level too.
        DB::table('regulatory_class_defaults')->whereNotNull('requires_coverage_code')->where('mapping_level', 'PRODUCT')->update(['mapping_level' => 'COVERAGE']);
        $created = 0;
        foreach (self::RULES as [$line, $coverage, $number, $type, $separate]) {
            if (! isset($codes[$number])) {
                continue;
            }
            $key = ['line_code' => $line, 'branch_code' => $codes[$number], 'relationship_type' => $type, 'requires_coverage_code' => $coverage, 'regulatory_version' => $version];
            if (DB::table('regulatory_class_defaults')->where($key)->exists()) {
                continue;
            }
            DB::table('regulatory_class_defaults')->insert($key + [
                'id' => (string) Str::uuid(), 'regime' => 'CIMA', 'mapping_level' => 'COVERAGE', 'separate_premium' => $separate,
                'effective_from' => '2026-01-01', 'source_reference' => self::SOURCE, 'status' => 'ACTIVE', 'is_seeded' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $created++;
        }
        DB::table('regulatory_class_defaults')->whereIn('line_code', self::REPLACED_PRODUCT_LEVEL)->whereNull('requires_coverage_code')->where('status', 'ACTIVE')
            ->update(['status' => 'SUPERSEDED', 'effective_until' => now()->toDateString(), 'updated_at' => now()]);

        return $created;
    }
}
