<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 4C — CRM (REQ-CRM-001, REQ-CRM-003, REQ-CRM-004). Additive only.
 *
 *  - partner_leads (Wave 16, canonical "leads" per matrix §1.1) gains the full
 *    pipeline NEW→CONTACTED→QUALIFIED→QUOTE→NEGOTIATION→CONVERTED(=WON)/LOST,
 *    an assignee and a tenant-level directory (partner_id becomes nullable for
 *    unassigned leads). Lead activities/follow-ups are NOT a new table: they are
 *    diary_entries with subject_type = 'partner_lead' (case engine, REQ-CAS-001),
 *    so follow-ups already surface in My Work and the SLA tick.
 *  - partner_lead_assignments: assignment history (WF-005..007).
 *  - portfolio_transfers: WF-079 bulk re-attribution; per-customer history stays
 *    in attribution_events (type TRANSFERRED), no parallel history table.
 *  - beneficiary_designations: WF-078 primary/contingent, allocations = 100 %,
 *    versioned history (a change supersedes the whole previous set).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_leads', function (Blueprint $t) {
            $t->foreignUuid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('source', 32)->nullable();
            $t->string('lost_reason', 255)->nullable();
            $t->uuid('quote_id')->nullable();
            $t->timestampTz('status_changed_at')->nullable();
            $t->index(['tenant_id', 'assigned_user_id']);
        });
        DB::statement('ALTER TABLE partner_leads ALTER COLUMN partner_id DROP NOT NULL');
        DB::statement('ALTER TABLE partner_leads DROP CONSTRAINT IF EXISTS partner_lead_status_allowed');
        DB::statement("ALTER TABLE partner_leads ADD CONSTRAINT partner_lead_status_allowed CHECK (status IN ('NEW','CONTACTED','QUALIFIED','QUOTE','NEGOTIATION','CONVERTED','LOST'))");

        Schema::create('partner_lead_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('lead_id')->constrained('partner_leads')->cascadeOnDelete();
            $t->foreignUuid('from_partner_id')->nullable()->constrained('partners');
            $t->foreignUuid('to_partner_id')->nullable()->constrained('partners');
            $t->foreignUuid('from_user_id')->nullable()->constrained('users');
            $t->foreignUuid('to_user_id')->nullable()->constrained('users');
            $t->string('rule', 32);
            $t->string('reason', 255)->nullable();
            $t->foreignUuid('actor_id')->constrained('users');
            $t->timestampTz('occurred_at');
            $t->index(['lead_id', 'occurred_at']);
        });

        Schema::create('portfolio_transfers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('from_partner_id')->constrained('partners');
            $t->foreignUuid('to_partner_id')->constrained('partners');
            $t->string('status', 16)->default('EXECUTED');
            $t->string('reason_code', 64);
            $t->text('notes')->nullable();
            $t->jsonb('party_ids');
            $t->unsignedInteger('customer_count');
            $t->unsignedInteger('policy_count');
            $t->string('preview_hash', 64);
            $t->foreignUuid('executed_by')->constrained('users');
            $t->timestampTz('executed_at');
            $t->timestampsTz();
            $t->index(['tenant_id', 'from_partner_id']);
        });

        Schema::create('beneficiary_designations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->unsignedInteger('set_version');
            $t->string('designation', 16);
            $t->foreignUuid('party_id')->nullable()->constrained('parties');
            $t->string('full_name', 160);
            $t->string('relationship', 32)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->decimal('allocation_pct', 5, 2);
            $t->boolean('revocable')->default(true);
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampTz('effective_from');
            $t->timestampTz('effective_to')->nullable();
            $t->string('reason', 255)->nullable();
            $t->foreignUuid('designated_by')->constrained('users');
            $t->timestampsTz();
            $t->index(['policy_id', 'status']);
            $t->index(['policy_id', 'set_version']);
        });
        DB::statement("ALTER TABLE beneficiary_designations ADD CONSTRAINT beneficiary_designation_allowed CHECK (designation IN ('PRIMARY','CONTINGENT'))");
        DB::statement("ALTER TABLE beneficiary_designations ADD CONSTRAINT beneficiary_status_allowed CHECK (status IN ('ACTIVE','SUPERSEDED'))");
        DB::statement('ALTER TABLE beneficiary_designations ADD CONSTRAINT beneficiary_allocation_range CHECK (allocation_pct > 0 AND allocation_pct <= 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiary_designations');
        Schema::dropIfExists('portfolio_transfers');
        Schema::dropIfExists('partner_lead_assignments');
        DB::statement('ALTER TABLE partner_leads DROP CONSTRAINT IF EXISTS partner_lead_status_allowed');
        DB::statement("ALTER TABLE partner_leads ADD CONSTRAINT partner_lead_status_allowed CHECK (status IN ('NEW','CONTACTED','QUALIFIED','CONVERTED','LOST'))");
        Schema::table('partner_leads', function (Blueprint $t) {
            $t->dropConstrainedForeignId('assigned_user_id');
            $t->dropColumn(['source', 'lost_reason', 'quote_id', 'status_changed_at']);
        });
    }
};
