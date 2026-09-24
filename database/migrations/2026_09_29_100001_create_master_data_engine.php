<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Institutional master data engine (docs/spec/INSTITUTIONAL_MASTER_DATA_CATALOGUE_V1.md).
 * Generic controlled lists loaded from database/data/master_data/*.json.
 * Seeded rows (is_seeded = true) are protected against deletion by a PostgreSQL
 * BEFORE DELETE trigger, mirrored in App\Models\MasterData\Concerns\ProtectsSeededMasterData.
 * Admins deactivate (status INACTIVE); nothing seeded is ever deleted.
 */
return new class extends Migration
{
    public const PROTECTED = ['master_data_domains', 'master_data_lists', 'master_data_values', 'master_data_aliases'];

    public function up(): void
    {
        Schema::create('master_data_domains', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 64)->unique();
            $t->unsignedSmallInteger('number')->nullable();
            $t->string('label_en');
            $t->string('label_fr');
            $t->text('description_en')->nullable();
            $t->text('description_fr')->nullable();
            $t->text('disclaimer_en')->nullable();
            $t->text('disclaimer_fr')->nullable();
            $t->string('source_type', 32)->default('PLATFORM_NORMALIZED');
            $t->string('source_file', 64)->nullable();
            $t->unsignedInteger('catalog_version')->default(1);
            $t->string('status', 16)->default('ACTIVE');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_seeded')->default(false);
            $t->timestamps();
        });

        Schema::create('master_data_lists', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('domain_id');
            $t->string('domain_code', 64);
            $t->string('code', 64);
            $t->string('label_en');
            $t->string('label_fr');
            $t->text('description_en')->nullable();
            $t->text('description_fr')->nullable();
            $t->text('note')->nullable();
            $t->string('parent_list_code', 64)->nullable();
            $t->string('selection', 8)->default('SINGLE');
            $t->boolean('allow_other')->default(false);
            $t->boolean('structure_only')->default(false);
            $t->string('source_type', 32)->default('PLATFORM_NORMALIZED');
            $t->string('source_reference', 500)->nullable();
            $t->string('version', 32)->default('2026.1');
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->string('status', 16)->default('ACTIVE');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_seeded')->default(false);
            $t->timestamps();
            $t->unique(['domain_code', 'code']);
            $t->foreign('domain_id')->references('id')->on('master_data_domains');
        });

        Schema::create('master_data_values', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('list_id');
            $t->string('domain_code', 64);
            $t->string('list_code', 64);
            $t->string('code', 128);
            $t->string('label_en');
            $t->string('label_fr');
            $t->text('description_en')->nullable();
            $t->text('description_fr')->nullable();
            $t->string('parent_code', 128)->nullable();
            $t->uuid('parent_value_id')->nullable();
            $t->jsonb('attributes')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->string('status', 16)->default('ACTIVE');
            $t->boolean('is_other')->default(false);
            $t->boolean('is_common')->default(false);
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->string('source_type', 32)->default('PLATFORM_NORMALIZED');
            $t->string('source_reference', 500)->nullable();
            $t->timestampTz('verified_at')->nullable();
            $t->uuid('verified_by')->nullable();
            $t->uuid('tenant_id')->nullable();          // private tenant value when set
            $t->uuid('merged_into_id')->nullable();     // redirect after a merge
            $t->text('search_text')->nullable();        // normalized labels + aliases
            $t->unsignedInteger('usage_count')->default(0);
            $t->boolean('is_seeded')->default(false);
            $t->timestampTz('admin_modified_at')->nullable();
            $t->timestamps();
            $t->unique(['list_id', 'code']);
            $t->index(['domain_code', 'list_code', 'status']);
            $t->index('parent_code');
            $t->foreign('list_id')->references('id')->on('master_data_lists');
        });

        Schema::create('master_data_aliases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('value_id');
            $t->string('alias');
            $t->string('normalized');
            $t->string('alias_type', 16)->default('ALIAS'); // ALIAS, ABBREVIATION, MERGED_CODE, TENANT
            $t->string('locale', 8)->nullable();
            $t->uuid('tenant_id')->nullable();
            $t->boolean('is_seeded')->default(false);
            $t->timestamps();
            $t->unique(['value_id', 'normalized', 'tenant_id']);
            $t->index('normalized');
            $t->foreign('value_id')->references('id')->on('master_data_values');
        });

        Schema::create('master_data_review_queue', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('list_id');
            $t->string('domain_code', 64);
            $t->string('list_code', 64);
            $t->text('raw_input');
            $t->string('normalized');
            $t->string('parent_code', 128)->nullable();
            $t->string('suggested_category')->nullable();
            $t->string('locale', 8)->nullable();
            $t->string('status', 24)->default('SUBMITTED'); // SUBMITTED, UNDER_REVIEW, DUPLICATE_FOUND, APPROVED, MERGED, REJECTED, ARCHIVED
            $t->uuid('proposer_user_id')->nullable();
            $t->uuid('tenant_id')->nullable();
            $t->string('screen', 128)->nullable();
            $t->string('line_code', 32)->nullable();
            $t->string('field_key', 64)->nullable();
            $t->jsonb('possible_duplicates')->nullable();
            $t->unsignedInteger('submission_count')->default(1);
            $t->jsonb('submissions')->nullable();
            $t->uuid('resolved_value_id')->nullable();
            $t->uuid('resolved_by')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->text('resolution_note')->nullable();
            $t->timestamps();
            $t->index(['list_id', 'normalized', 'status']);
            $t->index(['status', 'submission_count']);
        });

        Schema::create('master_data_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('entity_type', 32);
            $t->uuid('entity_id');
            $t->string('domain_code', 64)->nullable();
            $t->string('action', 32);
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->uuid('actor_id')->nullable();
            $t->string('source', 16)->default('ADMIN'); // SEEDER, ADMIN, API, IMPORT
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['entity_type', 'entity_id']);
            $t->index(['domain_code', 'created_at']);
        });

        Schema::create('master_data_tenant_overrides', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('value_id');
            $t->string('action', 16); // HIDE, ALIAS, INTERNAL_CODE
            $t->string('alias')->nullable();
            $t->string('internal_code', 128)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'value_id', 'action']);
            $t->foreign('value_id')->references('id')->on('master_data_values');
        });

        Schema::create('carrier_master_data_mappings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('carrier_id');
            $t->uuid('value_id');
            $t->string('target', 64)->default('CODE'); // CODE, occupation risk_class, location_risk_zone, ...
            $t->string('external_code', 128);
            $t->string('external_label')->nullable();
            $t->string('status', 16)->default('ACTIVE');
            $t->timestamps();
            $t->unique(['carrier_id', 'value_id', 'target']);
            $t->foreign('value_id')->references('id')->on('master_data_values');
        });

        Schema::create('broker_master_data_mappings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('partner_id');
            $t->uuid('value_id');
            $t->string('external_code', 128);
            $t->string('external_label')->nullable();
            $t->string('status', 16)->default('ACTIVE');
            $t->timestamps();
            $t->unique(['partner_id', 'value_id']);
            $t->foreign('value_id')->references('id')->on('master_data_values');
        });

        Schema::create('master_data_value_usage', function (Blueprint $t) {
            $t->uuid('value_id');
            $t->uuid('tenant_id');
            $t->unsignedInteger('uses')->default(0);
            $t->timestampTz('last_used_at')->nullable();
            $t->primary(['value_id', 'tenant_id']);
        });

        Schema::create('master_data_imports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('domain_code', 64);
            $t->string('list_code', 64);
            $t->string('format', 8);
            $t->string('filename')->nullable();
            $t->string('status', 16)->default('UPLOADED'); // UPLOADED, VALIDATED, APPROVED, IMPORTED, FAILED
            $t->jsonb('mapping')->nullable();
            $t->jsonb('rows')->nullable();
            $t->jsonb('report')->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestampTz('imported_at')->nullable();
            $t->timestamps();
        });

        Schema::create('health_providers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('provider_type_code', 64);
            $t->string('city_code', 64)->nullable();
            $t->string('city_name')->nullable();
            $t->string('region_code', 64)->nullable();
            $t->string('network_tier_code', 64)->nullable();
            $t->uuid('carrier_id')->nullable();
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampTz('verified_at')->nullable();
            $t->string('source_reference', 500)->nullable();
            $t->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_seeded_master_data_delete() RETURNS trigger AS $$
                BEGIN
                    IF OLD.is_seeded THEN
                        RAISE EXCEPTION 'Seeded master data row %.% cannot be deleted; set status INACTIVE instead', TG_TABLE_NAME, OLD.id;
                    END IF;
                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            foreach (self::PROTECTED as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_protect_seeded BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_seeded_master_data_delete();");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::PROTECTED as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_protect_seeded ON {$table};");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_seeded_master_data_delete();');
        }
        foreach (['health_providers', 'master_data_imports', 'master_data_value_usage', 'broker_master_data_mappings', 'carrier_master_data_mappings', 'master_data_tenant_overrides',
            'master_data_changes', 'master_data_review_queue', 'master_data_aliases', 'master_data_values', 'master_data_lists', 'master_data_domains'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
