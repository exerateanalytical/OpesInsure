<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: Cameroon official insurance register 2026 (DGTCFM/MINFI) per the
 * Institutional Seed Data Specification v1 (docs/spec/INSTITUTIONAL_SEED_SPEC_V1.md).
 *
 * Canonical IDs + provenance on carriers/partners, the insurance class
 * taxonomy, effective-dated insurer/intermediary authorizations and
 * seed_catalog_versions. Rows flagged is_official_register are protected:
 * the model layer refuses deletes and, on PostgreSQL, a BEFORE DELETE
 * trigger does too. Unknown institutional facts stay null (no invented data).
 */
return new class extends Migration
{
    private const PROTECTED = ['carriers', 'partners', 'insurance_classes', 'insurer_authorizations', 'intermediary_authorizations'];

    public function up(): void
    {
        $provenance = function (Blueprint $t): void {
            $t->string('canonical_id', 32)->nullable()->unique();
            $t->string('slug', 96)->nullable();
            $t->string('legal_name')->nullable();
            $t->string('trade_name')->nullable();
            $t->unsignedInteger('regulator_sequence')->nullable();
            $t->string('data_origin', 24)->nullable();          // REGULATORY | CARRIER_PUBLISHED | PLATFORM_NORMALIZED | DEMO_SYNTHETIC
            $t->string('source_authority', 32)->nullable();     // DGTCFM/MINFI
            $t->unsignedSmallInteger('reference_year')->nullable();
            $t->string('regulatory_status', 24)->nullable();    // AUTHORIZED
            $t->string('register_source', 32)->nullable();      // DGTCFM_2026
            $t->string('country_code', 2)->nullable();
            $t->boolean('is_official_register')->default(false)->index();
            $t->boolean('is_demo')->default(false);
        };

        Schema::table('carriers', function (Blueprint $t) use ($provenance) {
            $provenance($t);
            $t->string('insurer_code', 48)->nullable()->unique();
            $t->string('short_name', 64)->nullable();
            $t->string('licence_branch', 16)->nullable();        // IARD | LIFE | CAPITALIZATION
            $t->string('currency', 3)->nullable();
            $t->jsonb('product_families')->default('[]');
            $t->string('product_families_origin', 24)->nullable();   // CARRIER_PUBLISHED
            $t->string('product_families_status', 24)->nullable();   // UNVERIFIED
        });

        Schema::table('partners', function (Blueprint $t) use ($provenance) {
            $provenance($t);
        });

        Schema::create('insurance_classes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('parent_id')->nullable()->index();
            $t->string('branch', 16);
            $t->string('code', 64)->unique();
            $t->jsonb('name');
            $t->unsignedInteger('sort_order')->default(0);
            $t->string('data_origin', 24)->nullable();
            $t->string('source_authority', 32)->nullable();
            $t->unsignedSmallInteger('reference_year')->nullable();
            $t->string('register_source', 32)->nullable();
            $t->boolean('is_official_register')->default(false);
            $t->timestampsTz();
        });

        Schema::table('insurance_classes', fn (Blueprint $t) => $t->foreign('parent_id')->references('id')->on('insurance_classes'));

        Schema::create('insurer_authorizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_id')->constrained();
            $t->unsignedSmallInteger('reference_year');
            $t->string('branch', 16);
            $t->string('status', 24);
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('source_authority', 32);
            $t->string('data_origin', 24);
            $t->boolean('is_official_register')->default(false);
            $t->timestampsTz();
            $t->unique(['carrier_id', 'reference_year', 'branch']);
        });

        Schema::create('intermediary_authorizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('partner_id')->constrained();
            $t->string('intermediary_type', 24);
            $t->unsignedSmallInteger('reference_year');
            $t->unsignedInteger('regulator_sequence')->nullable();
            $t->string('status', 24);
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('source_authority', 32);
            $t->string('data_origin', 24);
            $t->boolean('is_official_register')->default(false);
            $t->timestampsTz();
            $t->unique(['partner_id', 'reference_year', 'intermediary_type']);
        });

        Schema::create('seed_catalog_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('dataset', 64);
            $t->string('version', 32);
            $t->date('effective_date');
            $t->string('source', 64);
            $t->string('status', 24);
            $t->jsonb('summary')->default('{}');
            $t->timestampsTz();
            $t->unique(['dataset', 'version']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_official_register_delete() RETURNS trigger AS $$
                BEGIN
                    IF OLD.is_official_register THEN
                        RAISE EXCEPTION 'Official register record %.% cannot be deleted', TG_TABLE_NAME, OLD.id;
                    END IF;
                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;
                SQL);
            foreach (self::PROTECTED as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_protect_official_register BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_official_register_delete();");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::PROTECTED as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_protect_official_register ON {$table};");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_official_register_delete();');
        }
        Schema::dropIfExists('seed_catalog_versions');
        Schema::dropIfExists('intermediary_authorizations');
        Schema::dropIfExists('insurer_authorizations');
        Schema::dropIfExists('insurance_classes');
        $common = ['canonical_id', 'slug', 'legal_name', 'trade_name', 'regulator_sequence', 'data_origin', 'source_authority', 'reference_year', 'regulatory_status', 'register_source', 'country_code', 'is_official_register', 'is_demo'];
        Schema::table('partners', fn (Blueprint $t) => $t->dropColumn($common));
        Schema::table('carriers', fn (Blueprint $t) => $t->dropColumn([...$common, 'insurer_code', 'short_name', 'licence_branch', 'currency', 'product_families', 'product_families_origin', 'product_families_status']));
    }
};
