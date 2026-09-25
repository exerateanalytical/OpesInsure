<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 8-7 — REQ-POL-009 (ICE gaps 36, 42).
 *
 *  - policies.servicing_partner_id / servicing_carrier_id: who services the
 *    policy now. NULL = unchanged since issuance (producing intermediary from
 *    customer_attributions / issuing carrier_id). carrier_id and commission
 *    attribution are never rewritten.
 *  - policy_portfolio_transfers (+ items): maker-checker batch of policies
 *    moving between intermediaries or carriers; customer notice or consent
 *    per item. Distinct from CRM portfolio_transfers (customer attribution).
 *  - policy_servicing_events: append-only per-policy servicing history.
 *  - policy_portability_exports: append-only register of export packs
 *    (hash of the JSON pack + PDF summary).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $t) {
            $t->foreignUuid('servicing_partner_id')->nullable()->constrained('partners');
            $t->foreignUuid('servicing_carrier_id')->nullable()->constrained('carriers');
        });

        Schema::create('policy_portfolio_transfers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('scope', 16);
            $t->uuid('from_id');
            $t->uuid('to_id');
            $t->string('status', 24);
            $t->string('reason_code', 64);
            $t->text('notes')->nullable();
            $t->string('notice_mode', 16);
            $t->timestampTz('effective_at');
            $t->jsonb('policy_ids');
            $t->unsignedInteger('policy_count');
            $t->string('preview_hash', 64);
            $t->foreignUuid('requested_by')->constrained('users');
            $t->timestampTz('requested_at');
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_notes')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE policy_portfolio_transfers ADD CONSTRAINT ppt_scope_allowed CHECK (scope IN ('INTERMEDIARY','CARRIER'))");
        DB::statement("ALTER TABLE policy_portfolio_transfers ADD CONSTRAINT ppt_status_allowed CHECK (status IN ('PENDING_APPROVAL','AWAITING_CONSENT','COMPLETED','REJECTED','CANCELLED'))");
        DB::statement("ALTER TABLE policy_portfolio_transfers ADD CONSTRAINT ppt_notice_allowed CHECK (notice_mode IN ('NOTICE','CONSENT'))");
        DB::statement('ALTER TABLE policy_portfolio_transfers ADD CONSTRAINT ppt_maker_checker CHECK (decided_by IS NULL OR decided_by <> requested_by)');
        DB::statement('ALTER TABLE policy_portfolio_transfers ADD CONSTRAINT ppt_distinct_ends CHECK (from_id <> to_id)');

        Schema::create('policy_portfolio_transfer_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('transfer_id')->constrained('policy_portfolio_transfers')->cascadeOnDelete();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->foreignUuid('party_id')->constrained('parties');
            $t->uuid('from_id')->nullable();
            $t->string('status', 24);
            $t->string('consent_status', 16);
            $t->string('consent_evidence', 255)->nullable();
            $t->foreignUuid('consent_recorded_by')->nullable()->constrained('users');
            $t->timestampTz('consent_recorded_at')->nullable();
            $t->timestampTz('notice_sent_at')->nullable();
            $t->timestampTz('applied_at')->nullable();
            $t->timestampsTz();
            $t->unique(['transfer_id', 'policy_id']);
            $t->index(['policy_id', 'status']);
        });
        DB::statement("ALTER TABLE policy_portfolio_transfer_items ADD CONSTRAINT ppti_status_allowed CHECK (status IN ('PENDING','AWAITING_CONSENT','APPLIED','EXCLUDED'))");
        DB::statement("ALTER TABLE policy_portfolio_transfer_items ADD CONSTRAINT ppti_consent_allowed CHECK (consent_status IN ('NOT_REQUIRED','PENDING','GRANTED','REFUSED'))");

        Schema::create('policy_servicing_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->foreignUuid('transfer_id')->nullable()->constrained('policy_portfolio_transfers');
            $t->string('scope', 16);
            $t->uuid('from_id')->nullable();
            $t->uuid('to_id');
            $t->timestampTz('effective_at');
            $t->foreignUuid('actor_id')->constrained('users');
            $t->timestampTz('occurred_at');
            $t->index(['policy_id', 'occurred_at']);
        });

        Schema::create('policy_portability_exports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->foreignUuid('party_id')->constrained('parties');
            $t->string('purpose', 32);
            $t->string('consent_reference', 191);
            $t->string('recipient', 191)->nullable();
            $t->unsignedSmallInteger('schema_version');
            $t->jsonb('pack');
            $t->string('pack_sha256', 64);
            $t->string('pdf_path', 255);
            $t->string('pdf_sha256', 64);
            $t->foreignUuid('requested_by')->constrained('users');
            $t->timestampTz('created_at');
            $t->index(['tenant_id', 'policy_id']);
        });
        DB::statement("ALTER TABLE policy_portability_exports ADD CONSTRAINT ppe_purpose_allowed CHECK (purpose IN ('CUSTOMER_REQUEST','INTERMEDIARY_TRANSFER','CARRIER_TRANSFER','REGULATOR_REQUEST'))");

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION policy_portability_append_only() RETURNS trigger AS $$
BEGIN RAISE EXCEPTION '% rows are append-only', TG_TABLE_NAME; END; $$ LANGUAGE plpgsql;
CREATE TRIGGER policy_servicing_events_append_only BEFORE UPDATE OR DELETE ON policy_servicing_events FOR EACH ROW EXECUTE FUNCTION policy_portability_append_only();
CREATE TRIGGER policy_portability_exports_append_only BEFORE UPDATE OR DELETE ON policy_portability_exports FOR EACH ROW EXECUTE FUNCTION policy_portability_append_only();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS policy_servicing_events_append_only ON policy_servicing_events; DROP TRIGGER IF EXISTS policy_portability_exports_append_only ON policy_portability_exports; DROP FUNCTION IF EXISTS policy_portability_append_only();');
        }
        Schema::dropIfExists('policy_portability_exports');
        Schema::dropIfExists('policy_servicing_events');
        Schema::dropIfExists('policy_portfolio_transfer_items');
        Schema::dropIfExists('policy_portfolio_transfers');
        Schema::table('policies', function (Blueprint $t) {
            $t->dropConstrainedForeignId('servicing_partner_id');
            $t->dropConstrainedForeignId('servicing_carrier_id');
        });
    }
};
