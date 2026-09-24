<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * REQ-TMP-001 / REQ-TMP-002 — ICE Engine 1 (Temporal / Reference-Date).
 * Additive only: new tables + recorded_at/superseded_at on tariff_versions,
 * insurance_products and insurer_authorizations (E1.3). Existing rows are
 * backfilled with recorded_at = created_at (best knowledge-time available).
 */
return new class extends Migration
{
    private const BITEMPORAL_TABLES = ['tariff_versions', 'insurance_products', 'insurer_authorizations'];

    public function up(): void
    {
        Schema::create('versioned_artifact_registry', function (Blueprint $t) {
            $t->string('artifact_type', 48)->primary();
            $t->string('source_table', 64);
            $t->jsonb('key_columns');
            $t->string('from_column', 48);
            $t->string('until_column', 48)->nullable();
            $t->string('granularity', 8);           // DAY (closed-closed, tenant tz) | INSTANT (half-open)
            $t->string('status_column', 48)->nullable();
            $t->jsonb('effective_statuses')->default('[]');
            $t->string('version_column', 48)->nullable();
            $t->string('rule_category', 24);        // TERMS | AUTHORITY — which reference_date_rules row applies
            $t->boolean('bitemporal')->default(false);
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE versioned_artifact_registry ADD CONSTRAINT var_granularity_allowed CHECK (granularity IN ('DAY','INSTANT'))");
        DB::statement("ALTER TABLE versioned_artifact_registry ADD CONSTRAINT var_category_allowed CHECK (rule_category IN ('TERMS','AUTHORITY'))");

        Schema::create('reference_date_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('operation', 48);
            $t->string('artifact_type', 48);        // TERMS | AUTHORITY category, or a specific registry artifact_type
            $t->string('anchor', 48);
            $t->unsignedInteger('version');
            $t->string('status', 24)->default('DRAFT');
            $t->timestampTz('valid_from');
            $t->timestampTz('valid_to')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestampTz('recorded_at')->useCurrent();
            $t->timestampTz('superseded_at')->nullable();
            $t->timestampsTz();
            $t->unique(['operation', 'artifact_type', 'version']);
        });
        DB::statement("ALTER TABLE reference_date_rules ADD CONSTRAINT rdr_status_allowed CHECK (status IN ('DRAFT','APPROVED','EFFECTIVE','SUPERSEDED','REJECTED'))");
        DB::statement("ALTER TABLE reference_date_rules ADD CONSTRAINT rdr_anchor_allowed CHECK (anchor IN ('REQUESTED_AT','OFFER_LOCK','INCEPTION','EFFECTIVE_AT','LOSS_OCCURRED_AT','DECISION_AT','VALUE_DATE','PERIOD_END','REQUEST_AT'))");
        DB::statement('ALTER TABLE reference_date_rules ADD CONSTRAINT rdr_valid_range CHECK (valid_to IS NULL OR valid_to > valid_from)');

        Schema::create('transaction_resolved_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $t->string('subject_type', 64);
            $t->uuid('subject_id');
            $t->string('operation', 48);
            $t->timestampTz('reference_at');
            $t->timestampTz('recorded_as_of');
            $t->jsonb('versions');
            $t->char('versions_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id']);
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION temporal_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'TEMPORAL_APPEND_ONLY: % on % is not allowed', TG_OP, TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER transaction_resolved_versions_append_only BEFORE UPDATE OR DELETE ON transaction_resolved_versions
                FOR EACH ROW EXECUTE FUNCTION temporal_append_only();
        SQL);

        // E1.3 — bitemporal knowledge-time columns (REQ-TMP-002).
        foreach (self::BITEMPORAL_TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestampTz('recorded_at')->nullable();
                $t->timestampTz('superseded_at')->nullable();
            });
            DB::statement("UPDATE {$table} SET recorded_at = COALESCE(created_at, now()) WHERE recorded_at IS NULL");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN recorded_at SET DEFAULT now()");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN recorded_at SET NOT NULL");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_recorded_range CHECK (superseded_at IS NULL OR superseded_at >= recorded_at)");
        }

        $now = now();
        $registry = [
            ['cancellation_rule', 'cancellation_rule_versions', ['line_code'], 'effective_from', 'effective_until', 'DAY', 'status', ['APPROVED'], 'version', 'TERMS', false],
            ['tariff', 'tariff_versions', ['insurance_product_id'], 'effective_from', 'effective_until', 'DAY', 'status', ['APPROVED'], 'version', 'TERMS', true],
            ['insurance_product', 'insurance_products', ['carrier_id', 'code'], 'effective_from', 'effective_until', 'DAY', 'status', ['ACTIVE'], 'version', 'TERMS', true],
            ['insurer_authorization', 'insurer_authorizations', ['carrier_id', 'branch'], 'effective_from', 'effective_until', 'DAY', 'status', ['AUTHORIZED'], 'reference_year', 'AUTHORITY', true],
        ];
        foreach ($registry as [$type, $table, $keys, $from, $until, $gran, $statusCol, $statuses, $versionCol, $category, $bi]) {
            DB::table('versioned_artifact_registry')->insert([
                'artifact_type' => $type, 'source_table' => $table, 'key_columns' => json_encode($keys),
                'from_column' => $from, 'until_column' => $until, 'granularity' => $gran, 'status_column' => $statusCol,
                'effective_statuses' => json_encode($statuses), 'version_column' => $versionCol, 'rule_category' => $category,
                'bitemporal' => $bi, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // ICE E1 §1.2 reference-date rules, version 1. Reinsurance cession
        // (treaty-basis dependent, Engine 7) is not seeded. ISSUE/TERMS uses
        // INCEPTION pending OPEN QUESTION OQ-1.1 (templates at issue vs inception).
        $rules = [
            ['QUOTE', 'TERMS', 'REQUESTED_AT'], ['QUOTE', 'AUTHORITY', 'REQUESTED_AT'],
            ['BIND', 'TERMS', 'OFFER_LOCK'], ['BIND', 'AUTHORITY', 'REQUEST_AT'],
            ['ISSUE', 'TERMS', 'INCEPTION'], ['ISSUE', 'AUTHORITY', 'REQUEST_AT'],
            ['ENDORSE', 'TERMS', 'EFFECTIVE_AT'], ['ENDORSE', 'AUTHORITY', 'REQUEST_AT'],
            ['CANCEL', 'TERMS', 'EFFECTIVE_AT'], ['CANCEL', 'AUTHORITY', 'REQUEST_AT'],
            ['RENEW', 'TERMS', 'INCEPTION'], ['RENEW', 'AUTHORITY', 'REQUEST_AT'],
            ['CLAIM_FNOL', 'TERMS', 'LOSS_OCCURRED_AT'], ['CLAIM_FNOL', 'AUTHORITY', 'REQUEST_AT'],
            ['CLAIM_DECISION', 'TERMS', 'LOSS_OCCURRED_AT'], ['CLAIM_DECISION', 'AUTHORITY', 'DECISION_AT'],
            ['SETTLEMENT', 'TERMS', 'DECISION_AT'], ['SETTLEMENT', 'FX_RATE', 'VALUE_DATE'], ['SETTLEMENT', 'AUTHORITY', 'REQUEST_AT'],
            ['COMMISSION', 'TERMS', 'EFFECTIVE_AT'],
            ['REGULATORY_REPORT', 'TERMS', 'PERIOD_END'],
            ['API_ACCESS', 'TERMS', 'REQUEST_AT'], ['API_ACCESS', 'AUTHORITY', 'REQUEST_AT'],
        ];
        foreach ($rules as [$op, $artifact, $anchor]) {
            DB::table('reference_date_rules')->insert([
                'id' => (string) Str::uuid(), 'operation' => $op, 'artifact_type' => $artifact, 'anchor' => $anchor,
                'version' => 1, 'status' => 'EFFECTIVE', 'valid_from' => '2020-01-01 00:00:00+01', 'notes' => 'Seeded from ICE E1 §1.2',
                'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::BITEMPORAL_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_recorded_range");
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['recorded_at', 'superseded_at']));
        }
        DB::unprepared('DROP TRIGGER IF EXISTS transaction_resolved_versions_append_only ON transaction_resolved_versions; DROP FUNCTION IF EXISTS temporal_append_only();');
        Schema::dropIfExists('transaction_resolved_versions');
        Schema::dropIfExists('reference_date_rules');
        Schema::dropIfExists('versioned_artifact_registry');
    }
};
