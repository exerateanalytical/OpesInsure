<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 7C — REQ-POL-002/003: structured, bitemporal policy chronology.
 *  - policy_versions: one row per contract version. valid_from/valid_to = business
 *    (effective) time; recorded_at/superseded_at = system time. snapshot = §84
 *    structured snapshot (immutable, hashed).
 *  - policy_parties / policy_risks / policy_coverages / policy_limits: normalised
 *    projections of each version (append-only; limits carry consumption counters).
 * policies.terms_snapshot/terms_hash remain written and are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->unsignedInteger('version_no');
            $t->string('kind', 24);
            $t->timestampTz('valid_from');
            $t->timestampTz('valid_to')->nullable();
            $t->timestampTz('recorded_at');
            $t->timestampTz('superseded_at')->nullable();
            $t->unsignedSmallInteger('schema_version')->default(1);
            $t->jsonb('snapshot');
            $t->string('snapshot_hash', 64);
            $t->string('terms_hash', 64)->nullable();
            $t->string('source_type', 64)->nullable();
            $t->uuid('source_id')->nullable();
            $t->foreignUuid('recorded_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->unique(['policy_id', 'version_no']);
            $t->index(['policy_id', 'valid_from']);
        });
        DB::statement("ALTER TABLE policy_versions ADD CONSTRAINT policy_versions_kind_allowed CHECK (kind IN ('ISSUANCE','ENDORSEMENT','RENEWAL','REINSTATEMENT','CANCELLATION','BACKFILL'))");
        DB::statement('ALTER TABLE policy_versions ADD CONSTRAINT policy_versions_valid_range CHECK (valid_to IS NULL OR valid_to >= valid_from)');

        Schema::create('policy_parties', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->foreignUuid('policy_version_id')->constrained('policy_versions');
            $t->string('role', 32);
            $t->foreignUuid('party_id')->nullable()->constrained('parties');
            $t->string('display_name', 191)->nullable();
            $t->unsignedInteger('allocation_bp')->nullable();
            $t->jsonb('attributes')->default('{}');
            $t->timestampsTz();
            $t->index(['policy_version_id', 'role']);
        });
        DB::statement("ALTER TABLE policy_parties ADD CONSTRAINT policy_parties_role_allowed CHECK (role IN ('POLICYHOLDER','INSURED','BENEFICIARY_PRIMARY','BENEFICIARY_CONTINGENT','PAYER'))");

        Schema::create('policy_risks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->foreignUuid('policy_version_id')->constrained('policy_versions');
            $t->string('risk_type', 32);
            $t->foreignUuid('risk_asset_id')->nullable()->constrained('risk_assets');
            $t->string('display_name', 191)->nullable();
            $t->jsonb('facts')->default('{}');
            $t->string('facts_hash', 64);
            $t->timestampsTz();
            $t->index('policy_version_id');
        });

        Schema::create('policy_coverages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->foreignUuid('policy_version_id')->constrained('policy_versions');
            $t->string('coverage_code', 64);
            $t->jsonb('name')->nullable();
            $t->boolean('mandatory')->default(false);
            $t->boolean('optional')->default(false);
            $t->bigInteger('limit_minor')->nullable();
            $t->bigInteger('deductible_minor')->nullable();
            $t->bigInteger('premium_minor')->nullable();
            $t->string('currency', 3);
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->timestampsTz();
            $t->unique(['policy_version_id', 'coverage_code']);
        });

        Schema::create('policy_limits', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->foreignUuid('policy_version_id')->constrained('policy_versions');
            $t->foreignUuid('policy_coverage_id')->nullable()->constrained('policy_coverages');
            $t->string('limit_type', 24);
            $t->bigInteger('amount_minor');
            $t->bigInteger('consumed_minor')->default(0);
            $t->bigInteger('reserved_minor')->default(0);
            $t->string('currency', 3);
            $t->timestampsTz();
            $t->index(['policy_id', 'limit_type']);
        });
        DB::statement("ALTER TABLE policy_limits ADD CONSTRAINT policy_limits_type_allowed CHECK (limit_type IN ('PER_CLAIM','AGGREGATE','DEDUCTIBLE'))");
        DB::statement('ALTER TABLE policy_limits ADD CONSTRAINT policy_limits_non_negative CHECK (amount_minor >= 0 AND consumed_minor >= 0 AND reserved_minor >= 0)');

        if (DB::getDriverName() === 'pgsql') {
            // A recorded version's content never changes; only its closing
            // timestamps (valid_to / superseded_at) may be set, once.
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION policy_versions_immutable() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'policy_versions rows are immutable'; END IF;
  IF NEW.snapshot IS DISTINCT FROM OLD.snapshot OR NEW.snapshot_hash IS DISTINCT FROM OLD.snapshot_hash
     OR NEW.version_no IS DISTINCT FROM OLD.version_no OR NEW.valid_from IS DISTINCT FROM OLD.valid_from
     OR NEW.recorded_at IS DISTINCT FROM OLD.recorded_at OR NEW.policy_id IS DISTINCT FROM OLD.policy_id
     OR NEW.kind IS DISTINCT FROM OLD.kind OR NEW.terms_hash IS DISTINCT FROM OLD.terms_hash
     OR (OLD.valid_to IS NOT NULL AND NEW.valid_to IS DISTINCT FROM OLD.valid_to)
     OR (OLD.superseded_at IS NOT NULL AND NEW.superseded_at IS DISTINCT FROM OLD.superseded_at) THEN
    RAISE EXCEPTION 'policy_versions rows are immutable';
  END IF;
  RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER policy_versions_immutable BEFORE UPDATE OR DELETE ON policy_versions FOR EACH ROW EXECUTE FUNCTION policy_versions_immutable();
CREATE OR REPLACE FUNCTION policy_chronology_append_only() RETURNS trigger AS $$
BEGIN RAISE EXCEPTION '% rows are append-only', TG_TABLE_NAME; END; $$ LANGUAGE plpgsql;
CREATE TRIGGER policy_parties_append_only BEFORE UPDATE OR DELETE ON policy_parties FOR EACH ROW EXECUTE FUNCTION policy_chronology_append_only();
CREATE TRIGGER policy_risks_append_only BEFORE UPDATE OR DELETE ON policy_risks FOR EACH ROW EXECUTE FUNCTION policy_chronology_append_only();
CREATE TRIGGER policy_coverages_append_only BEFORE UPDATE OR DELETE ON policy_coverages FOR EACH ROW EXECUTE FUNCTION policy_chronology_append_only();
CREATE OR REPLACE FUNCTION policy_limits_guard() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'policy_limits rows are append-only'; END IF;
  IF NEW.amount_minor IS DISTINCT FROM OLD.amount_minor OR NEW.limit_type IS DISTINCT FROM OLD.limit_type
     OR NEW.policy_version_id IS DISTINCT FROM OLD.policy_version_id OR NEW.currency IS DISTINCT FROM OLD.currency THEN
    RAISE EXCEPTION 'policy_limits terms are immutable';
  END IF;
  RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER policy_limits_guard BEFORE UPDATE OR DELETE ON policy_limits FOR EACH ROW EXECUTE FUNCTION policy_limits_guard();
SQL);
        }
    }

    public function down(): void
    {
        foreach (['policy_limits', 'policy_coverages', 'policy_risks', 'policy_parties', 'policy_versions'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS policy_versions_immutable() CASCADE; DROP FUNCTION IF EXISTS policy_chronology_append_only() CASCADE; DROP FUNCTION IF EXISTS policy_limits_guard() CASCADE;');
        }
    }
};
