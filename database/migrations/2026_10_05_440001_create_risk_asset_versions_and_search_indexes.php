<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-RSK-001: immutable per-version snapshots of an insured object
 * (risk_assets stays the current row; risk_asset_events keeps the change log).
 * REQ-SRC-001: pg_trgm + trigram GIN indexes backing GET /api/v1/search.
 * Additive only. pg_trgm is a trusted extension (PG13+), so the database owner
 * can create it; if that fails the search falls back to plain ILIKE ordering.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('risk_asset_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('risk_asset_id')->constrained('risk_assets')->cascadeOnDelete();
            $t->foreignUuid('tenant_id')->constrained();
            $t->unsignedInteger('version');
            $t->string('type', 32);
            $t->string('display_name');
            $t->string('external_reference', 100)->nullable();
            $t->string('status', 24);
            $t->jsonb('facts');
            $t->string('facts_hash', 64);
            $t->uuid('recorded_by')->nullable();
            $t->timestampTz('recorded_at');
            $t->unique(['risk_asset_id', 'version']);
        });

        // Backfill the current version of every existing asset (history before this migration is in risk_asset_events).
        DB::statement(<<<'SQL'
            INSERT INTO risk_asset_versions (id, risk_asset_id, tenant_id, version, type, display_name, external_reference, status, facts, facts_hash, recorded_at)
            SELECT gen_random_uuid(), id, tenant_id, version, type, display_name, external_reference, status, facts, facts_hash, COALESCE(updated_at, created_at, now())
            FROM risk_assets
            ON CONFLICT DO NOTHING
        SQL);

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            report($e);

            return;
        }

        foreach ([
            'search_parties_display_name_trgm' => 'parties USING gin (display_name gin_trgm_ops)',
            'search_tenant_customers_number_trgm' => 'tenant_customers USING gin (customer_number gin_trgm_ops)',
            'search_policies_number_trgm' => 'policies USING gin (policy_number gin_trgm_ops)',
            'search_policies_certificate_trgm' => 'policies USING gin (certificate_number gin_trgm_ops)',
            'search_claims_number_trgm' => 'claims USING gin (claim_number gin_trgm_ops)',
            'search_risk_assets_display_name_trgm' => 'risk_assets USING gin (display_name gin_trgm_ops)',
            'search_documents_number_trgm' => 'documents USING gin (document_number gin_trgm_ops)',
            'search_documents_title_trgm' => 'documents USING gin (title gin_trgm_ops)',
            'search_rav_plate_norm_trgm' => "risk_asset_vehicles USING gin ((regexp_replace(upper(registration_number), '[^A-Z0-9]', '', 'g')) gin_trgm_ops)",
            'search_rav_vin_norm_trgm' => "risk_asset_vehicles USING gin ((regexp_replace(upper(vin), '[^A-Z0-9]', '', 'g')) gin_trgm_ops)",
        ] as $name => $def) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$def}");
        }
    }

    public function down(): void
    {
        foreach (['search_parties_display_name_trgm', 'search_tenant_customers_number_trgm', 'search_policies_number_trgm', 'search_policies_certificate_trgm', 'search_claims_number_trgm', 'search_risk_assets_display_name_trgm', 'search_documents_number_trgm', 'search_documents_title_trgm', 'search_rav_plate_norm_trgm', 'search_rav_vin_norm_trgm'] as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
        Schema::dropIfExists('risk_asset_versions');
    }
};
