<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | S5 (2026-09-29): carrier ownership of reinsurance / co-insurance records, so two insurers of one organisation never
 | see each other's treaties (App\Application\Reinsurance\RiskTransferCarrierScope). Additive: nullable, indexed.
 | Backfill (idempotent, only rows still NULL, only where the carrier is unambiguous):
 |  - facultative placements and policy-linked co-insurance arrangements: the policy's carrier;
 |  - treaties: the single carrier of the policies they ceded (reinsurance_cessions);
 |  - reinsurers: the single carrier of the treaties / facultative slips they take part in.
 | Anything else stays NULL = tenant-wide legacy row, visible to tenant-wide / platform users only.
 */
return new class extends Migration
{
    private const TABLES = ['reinsurers', 'reinsurance_treaties', 'facultative_placements', 'coinsurance_arrangements'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'carrier_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->foreignUuid('carrier_id')->nullable()->constrained('carriers')->nullOnDelete();
                    $t->index(['tenant_id', 'carrier_id'], $table.'_tenant_carrier_idx');
                });
            }
        }

        foreach (['facultative_placements', 'coinsurance_arrangements'] as $table) {
            DB::statement("UPDATE {$table} x SET carrier_id = p.carrier_id FROM policies p
                WHERE x.carrier_id IS NULL AND x.policy_id = p.id AND p.carrier_id IS NOT NULL");
        }

        DB::statement('UPDATE reinsurance_treaties t SET carrier_id = s.carrier_id FROM (
                SELECT c.treaty_id, MIN(p.carrier_id::text)::uuid AS carrier_id FROM reinsurance_cessions c JOIN policies p ON p.id = c.policy_id
                WHERE c.treaty_id IS NOT NULL AND p.carrier_id IS NOT NULL GROUP BY c.treaty_id HAVING COUNT(DISTINCT p.carrier_id) = 1
            ) s WHERE t.carrier_id IS NULL AND t.id = s.treaty_id');

        DB::statement('UPDATE reinsurers r SET carrier_id = s.carrier_id FROM (
                SELECT u.reinsurer_id, MIN(u.carrier_id::text)::uuid AS carrier_id FROM (
                    SELECT tp.reinsurer_id, t.carrier_id FROM reinsurance_treaty_participants tp
                        JOIN reinsurance_treaty_versions v ON v.id = tp.treaty_version_id JOIN reinsurance_treaties t ON t.id = v.treaty_id
                    UNION ALL SELECT tp.broker_id, t.carrier_id FROM reinsurance_treaty_participants tp
                        JOIN reinsurance_treaty_versions v ON v.id = tp.treaty_version_id JOIN reinsurance_treaties t ON t.id = v.treaty_id WHERE tp.broker_id IS NOT NULL
                    UNION ALL SELECT fp.reinsurer_id, f.carrier_id FROM facultative_participants fp JOIN facultative_placements f ON f.id = fp.placement_id
                    UNION ALL SELECT f.broker_id, f.carrier_id FROM facultative_placements f WHERE f.broker_id IS NOT NULL
                ) u GROUP BY u.reinsurer_id HAVING COUNT(*) FILTER (WHERE u.carrier_id IS NULL) = 0 AND COUNT(DISTINCT u.carrier_id) = 1
            ) s WHERE r.carrier_id IS NULL AND r.id = s.reinsurer_id');
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'carrier_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex($table.'_tenant_carrier_idx');
                    $t->dropConstrainedForeignId('carrier_id');
                });
            }
        }
    }
};
