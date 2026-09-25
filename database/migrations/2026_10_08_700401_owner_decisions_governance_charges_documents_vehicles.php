<?php

declare(strict_types=1);

use App\Application\Vehicles\CuratedVehicleReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decisions 2026-09-25 (docs/spec/OWNER_DECISIONS_2026-09-25.md), additive only:
 *
 *  item 28  insurance_products.governance_mode: GOVERNED (default for every version created from now on) or
 *           LEGACY_GRANDFATHERED (every version that exists at cutover). Only grandfathered versions may still use the
 *           direct submit / publish path; product_governance gains the SANDBOX_TESTS stage.
 *  item 10  tax_levy_versions / fee_schedule_versions: legal_basis + verification_status (DEMO | UNVERIFIED |
 *           PENDING_VERIFICATION | VERIFIED) + maker-checker verification evidence. OWNER_CONFIRMED is only possible
 *           with a VERIFIED, two-person verification (CHECK constraint): demo rates never silently become production rates.
 *  item 31  proposal_documents.verification_method (MANUAL | AUTOMATED_CONTROL) + automated_control_code;
 *           insurance_products.submission_accepts_unreviewed_documents (NULL = platform default).
 *  item 20  Datsun and Mahindra added to the curated reference on already-seeded databases (no models invented).
 */
return new class extends Migration
{
    private const CHARGE_TABLES = ['tax_levy_versions', 'fee_schedule_versions'];

    public function up(): void
    {
        // ---- item 28: governance cutover
        Schema::table('insurance_products', function (Blueprint $t): void {
            $t->string('governance_mode', 24)->nullable();
            $t->boolean('submission_accepts_unreviewed_documents')->nullable();
        });
        DB::table('insurance_products')->update(['governance_mode' => 'LEGACY_GRANDFATHERED']);
        DB::statement("ALTER TABLE insurance_products ALTER COLUMN governance_mode SET DEFAULT 'GOVERNED'");
        DB::statement('ALTER TABLE insurance_products ALTER COLUMN governance_mode SET NOT NULL');
        DB::statement("ALTER TABLE insurance_products ADD CONSTRAINT insurance_products_governance_mode CHECK (governance_mode IN ('GOVERNED','LEGACY_GRANDFATHERED'))");

        DB::statement('ALTER TABLE product_governance DROP CONSTRAINT IF EXISTS product_governance_stage');
        DB::statement("ALTER TABLE product_governance ADD CONSTRAINT product_governance_stage CHECK (stage IN ('DRAFT','CONFIGURATION','TECHNICAL_REVIEW','COMPLIANCE_REVIEW','BUSINESS_APPROVAL','SANDBOX_TESTS','READY','PUBLISHED','REJECTED'))");

        // ---- item 10: tax / levy / fee verification
        foreach (self::CHARGE_TABLES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->text('legal_basis')->nullable();
                $t->string('verification_status', 24)->default('DEMO');
                $t->foreignUuid('verification_requested_by')->nullable()->constrained('users');
                $t->timestampTz('verification_requested_at')->nullable();
                $t->jsonb('verification_evidence')->default('{}');
                $t->foreignUuid('verified_by')->nullable()->constrained('users');
                $t->timestampTz('verified_at')->nullable();
                $t->text('verification_notes')->nullable();
            });
            // Rows marked OWNER_CONFIRMED before this decision were confirmed without a maker-checker verification:
            // they go back to UNVERIFIED (kept, not deleted) and must be verified explicitly.
            DB::table($table)->where('data_status', 'OWNER_CONFIRMED')->update(['data_status' => 'DEMO_UNVERIFIED', 'verification_status' => 'UNVERIFIED',
                'verification_notes' => 'Owner decision 10 (2026-09-25): confirmed without maker-checker verification; re-verification required.']);
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_verification_allowed CHECK (verification_status IN ('DEMO','UNVERIFIED','PENDING_VERIFICATION','VERIFIED'))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_confirmed_needs_verification CHECK (
                (data_status = 'OWNER_CONFIRMED') = (verification_status = 'VERIFIED')
                AND (verification_status <> 'VERIFIED' OR (legal_basis IS NOT NULL AND source_reference IS NOT NULL AND verified_by IS NOT NULL
                     AND verification_requested_by IS NOT NULL AND verified_by <> verification_requested_by AND verified_at IS NOT NULL)))");
        }

        // ---- item 31: issuance acceptance evidence
        Schema::table('proposal_documents', function (Blueprint $t): void {
            $t->string('verification_method', 24)->nullable();
            $t->string('automated_control_code', 64)->nullable();
        });
        DB::table('proposal_documents')->where('status', 'VERIFIED')->whereNotNull('verified_by')->update(['verification_method' => 'MANUAL']);
        DB::statement("ALTER TABLE proposal_documents ADD CONSTRAINT proposal_documents_verification_method CHECK (verification_method IS NULL OR verification_method IN ('MANUAL','AUTOMATED_CONTROL'))");

        // ---- item 20: curated reference makes on databases already seeded
        if (DB::table('vehicle_makes')->exists()) {
            app(CuratedVehicleReference::class)->apply();
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE proposal_documents DROP CONSTRAINT IF EXISTS proposal_documents_verification_method');
        Schema::table('proposal_documents', fn (Blueprint $t) => $t->dropColumn(['verification_method', 'automated_control_code']));
        foreach (self::CHARGE_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_confirmed_needs_verification");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_verification_allowed");
            Schema::table($table, function (Blueprint $t): void {
                $t->dropConstrainedForeignId('verification_requested_by');
                $t->dropConstrainedForeignId('verified_by');
                $t->dropColumn(['legal_basis', 'verification_status', 'verification_requested_at', 'verification_evidence', 'verified_at', 'verification_notes']);
            });
        }
        DB::statement('ALTER TABLE product_governance DROP CONSTRAINT IF EXISTS product_governance_stage');
        DB::statement("ALTER TABLE product_governance ADD CONSTRAINT product_governance_stage CHECK (stage IN ('DRAFT','CONFIGURATION','TECHNICAL_REVIEW','COMPLIANCE_REVIEW','BUSINESS_APPROVAL','READY','PUBLISHED','REJECTED'))");
        DB::statement('ALTER TABLE insurance_products DROP CONSTRAINT IF EXISTS insurance_products_governance_mode');
        Schema::table('insurance_products', fn (Blueprint $t) => $t->dropColumn(['governance_mode', 'submission_accepts_unreviewed_documents']));
    }
};
