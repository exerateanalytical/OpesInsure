<?php

declare(strict_types=1);

use App\Application\Cases\CaseTypeCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow Institutional Data Master v1 (database/data/workflow_institutional_data_master_2026.json) — additive
 * reconciliation of the sections owned by App\Application\DataReadiness. Nothing is deleted or renamed.
 *
 *  - case_management.case_families: the owner's 10 families REPLACE the 8 reconstructed ones. Owner families are
 *    upserted (source OWNER_CONFIRMED), case types and open/closed cases are remapped, reconstructed families stay
 *    as inactive rows (history; the FK keeps pointing at real rows).
 *  - case_management.priorities: CRITICAL added to the cases priority CHECK.
 *  - notifications.delivery_statuses: BOUNCED and READ added to the delivery status CHECK (platform states SENDING /
 *    DEAD_LETTERED kept).
 *  - broker_insurer_agreements.required_fields: document_issuance_rights, policy_servicing_rights,
 *    claims_assistance_rights (per agreement line, like can_quote / can_bind), settlement_terms and source_document
 *    (per agreement). territory = existing `territories`.
 */
return new class extends Migration
{
    private const RECONSTRUCTED = ['QUOTATION', 'COMPLAINTS', 'RECOVERY_LEGAL', 'COMPLIANCE', 'DATA_QUALITY'];

    public function up(): void
    {
        $now = now();

        // ---- case families ------------------------------------------------------------------------------
        $i = 0;
        foreach (CaseTypeCatalogue::OWNER_FAMILIES as $code => [$name]) {
            $row = ['name' => $name, 'source' => 'OWNER_CONFIRMED', 'sort_order' => ++$i * 10, 'active' => true, 'updated_at' => $now,
                'description' => 'Owner case family (Workflow Data Master v1, case_management.case_families).'];
            if (DB::table('case_families')->where('code', $code)->exists()) {
                DB::table('case_families')->where('code', $code)->update($row);
            } else {
                DB::table('case_families')->insert($row + ['code' => $code, 'created_at' => $now]);
            }
        }
        foreach (CaseTypeCatalogue::OWNER_FAMILIES as $code => [, $types]) {
            if ($types !== []) {
                DB::table('case_types')->whereIn('code', $types)->update(['family_code' => $code]);
            }
        }
        DB::statement('UPDATE cases c SET case_family = ct.family_code FROM case_types ct WHERE ct.id = c.case_type_id AND c.case_family IS DISTINCT FROM ct.family_code');
        $owner = array_keys(CaseTypeCatalogue::OWNER_FAMILIES);
        DB::table('case_families')->whereNotIn('code', $owner)->where('source', 'RECONSTRUCTED_PENDING_OWNER')->update(['active' => false, 'updated_at' => $now,
            'description' => 'Superseded by the owner case families (Workflow Data Master v1). Kept as history; its case types were remapped.']);

        // ---- priorities ----------------------------------------------------------------------------------
        DB::statement('ALTER TABLE cases DROP CONSTRAINT IF EXISTS cases_priority_allowed');
        DB::statement("ALTER TABLE cases ADD CONSTRAINT cases_priority_allowed CHECK (priority IN ('LOW','NORMAL','HIGH','URGENT','CRITICAL'))");

        // ---- notification delivery statuses -------------------------------------------------------------
        if (Schema::hasTable('notification_deliveries')) {
            DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT IF EXISTS notification_status_check');
            DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_status_check CHECK (status IN ('QUEUED','SENDING','SENT','DELIVERED','READ','BOUNCED','FAILED','DEAD_LETTERED','CANCELLED'))");
        }

        // ---- broker / insurer agreement fields ----------------------------------------------------------
        Schema::table('carrier_broker_agreement_products', function (Blueprint $t): void {
            $t->boolean('can_issue_documents')->default(false);
            $t->boolean('can_service_policies')->default(false);
            $t->boolean('can_assist_claims')->default(false);
        });
        Schema::table('carrier_broker_agreements', function (Blueprint $t): void {
            $t->jsonb('settlement_terms')->nullable();            // NULL = CONFIG_REQUIRED (never invented)
            $t->string('source_document', 255)->nullable();       // signed agreement reference / document id
        });
    }

    public function down(): void
    {
        Schema::table('carrier_broker_agreements', fn (Blueprint $t) => $t->dropColumn(['settlement_terms', 'source_document']));
        Schema::table('carrier_broker_agreement_products', fn (Blueprint $t) => $t->dropColumn(['can_issue_documents', 'can_service_policies', 'can_assist_claims']));
        if (Schema::hasTable('notification_deliveries')) {
            DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT IF EXISTS notification_status_check');
            DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_status_check CHECK (status IN ('QUEUED','SENDING','SENT','DELIVERED','FAILED','DEAD_LETTERED','CANCELLED'))");
        }
        DB::statement("UPDATE cases SET priority = 'URGENT' WHERE priority = 'CRITICAL'");
        DB::statement('ALTER TABLE cases DROP CONSTRAINT IF EXISTS cases_priority_allowed');
        DB::statement("ALTER TABLE cases ADD CONSTRAINT cases_priority_allowed CHECK (priority IN ('LOW','NORMAL','HIGH','URGENT'))");
        DB::table('case_families')->whereIn('code', self::RECONSTRUCTED)->update(['active' => true]);
    }
};
