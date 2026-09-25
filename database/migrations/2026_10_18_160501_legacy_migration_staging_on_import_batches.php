<?php

declare(strict_types=1);

use App\Application\Approvals\ApprovalActionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * REQ-IMP-002 legacy integration & data migration (BP W26, MPS §92). Additive: legacy batches reuse import_batches
 * (same upload / audit trail as REQ-IMP-001) with the extra stages upload → validate → dry-run → reconcile →
 * approve (maker-checker) → commit, and rollback of an uncommitted batch.
 */
return new class extends Migration
{
    private const ACTION = 'legacy_migration.commit';

    private const PROPOSAL_STATUSES = "'DRAFT', 'DISCLOSURES_PENDING', 'DOCUMENTS_PENDING', 'SUBMITTED', 'UNDER_REVIEW', 'INFORMATION_REQUIRED', 'RESUBMITTED', "
        ."'APPROVED', 'COUNTEROFFERED', 'DECLINED', 'PAYMENT_PENDING', 'WITHDRAWN'";

    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $t) {
            $t->string('pipeline', 16)->default('GENERIC');     // GENERIC (REQ-IMP-001) | LEGACY (REQ-IMP-002)
            $t->string('source_system', 64)->nullable();         // legacy system code (one integration client per system)
            $t->jsonb('control_totals')->nullable();             // totals declared by the legacy system {count, amount_minor}
            $t->jsonb('dry_run')->nullable();                    // per-row outcome of the last dry run (nothing persisted)
            $t->jsonb('reconciliation')->nullable();             // source vs migrated totals per entity
            $t->timestampTz('reconciled_at')->nullable();
            $t->timestampTz('rolled_back_at')->nullable();
            $t->uuid('rolled_back_by')->nullable();
            $t->index(['pipeline', 'source_system', 'status']);
        });

        // A migrated policy keeps the quote → offer → proposal chain the model requires, marked MIGRATED (never re-rated).
        DB::statement('ALTER TABLE quote_offers DROP CONSTRAINT IF EXISTS quote_offers_origin_allowed');
        DB::statement("ALTER TABLE quote_offers ADD CONSTRAINT quote_offers_origin_allowed CHECK (origin IN ('RATED', 'MANUAL', 'MIGRATED'))");
        DB::statement('ALTER TABLE quote_offers DROP CONSTRAINT IF EXISTS quote_offers_tariff_or_manual');
        DB::statement("ALTER TABLE quote_offers ADD CONSTRAINT quote_offers_tariff_or_manual CHECK (tariff_version_id IS NOT NULL OR (origin = 'MANUAL' AND carrier_quote_response_id IS NOT NULL) OR origin = 'MIGRATED')");
        DB::statement('ALTER TABLE proposals DROP CONSTRAINT IF EXISTS proposal_status_allowed');
        DB::statement('ALTER TABLE proposals ADD CONSTRAINT proposal_status_allowed CHECK (status IN ('.self::PROPOSAL_STATUSES.", 'MIGRATED'))");

        // Approval matrix default for the new catalogued action (the defaults migration already ran in production).
        $a = ApprovalActionCatalogue::ACTIONS[self::ACTION] ?? null;
        if ($a && ! DB::table('approval_matrix_rules')->whereNull('tenant_id')->where('action_code', self::ACTION)->exists()) {
            DB::table('approval_matrix_rules')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => null, 'action_code' => self::ACTION, 'workflow' => $a['workflow'], 'category' => $a['category'],
                'description' => $a['description'], 'source_refs' => $a['sources'], 'checker_permission' => $a['checker_permission'] ?? null,
                'required_approvals' => 1, 'requires_maker_checker' => true, 'exclude_subject_parties' => true, 'priority' => 1000,
                'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('approval_matrix_rules')->whereNull('tenant_id')->where('action_code', self::ACTION)->delete();
        DB::statement('ALTER TABLE proposals DROP CONSTRAINT IF EXISTS proposal_status_allowed');
        DB::statement('ALTER TABLE proposals ADD CONSTRAINT proposal_status_allowed CHECK (status IN ('.self::PROPOSAL_STATUSES.'))');
        DB::statement('ALTER TABLE quote_offers DROP CONSTRAINT IF EXISTS quote_offers_tariff_or_manual');
        DB::statement("ALTER TABLE quote_offers ADD CONSTRAINT quote_offers_tariff_or_manual CHECK (tariff_version_id IS NOT NULL OR (origin = 'MANUAL' AND carrier_quote_response_id IS NOT NULL))");
        DB::statement('ALTER TABLE quote_offers DROP CONSTRAINT IF EXISTS quote_offers_origin_allowed');
        DB::statement("ALTER TABLE quote_offers ADD CONSTRAINT quote_offers_origin_allowed CHECK (origin IN ('RATED', 'MANUAL'))");
        Schema::table('import_batches', function (Blueprint $t) {
            $t->dropIndex(['pipeline', 'source_system', 'status']);
            $t->dropColumn(['pipeline', 'source_system', 'control_totals', 'dry_run', 'reconciliation', 'reconciled_at', 'rolled_back_at', 'rolled_back_by']);
        });
    }
};
