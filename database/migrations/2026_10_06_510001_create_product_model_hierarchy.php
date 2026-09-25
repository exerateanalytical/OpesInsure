<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 5A — product model (REQ-PRD-001…006).
 *
 * Canonical choice (no parallel tables): an insurance_products row IS a product
 * version (every existing FK — tariffs, quote offers, mappings, document rules —
 * keeps pointing at it). This migration adds the missing levels around it:
 *   regulatory_branches (CIMA branch) → insurance_classes (class) → product_families
 *   → carrier_products (stable identity, EN/FR attributes) → insurance_products (version)
 *   → product_plans → product_coverages (+ typed coverage_limits / coverage_deductibles)
 * and exposes the blueprint name `product_versions` as a read-only VIEW with the
 * PRE §8 status vocabulary (storage IN_REVIEW = REVIEW, ACTIVE = PUBLISHED).
 */
return new class extends Migration
{
    private const CLASS_CODES = ['MOTOR', 'HEALTH', 'PERSONAL_ACCIDENT', 'PROPERTY', 'HOME', 'FIRE', 'TRAVEL', 'LIABILITY', 'PROFESSIONAL_LIABILITY',
        'BUSINESS_MULTIRISK', 'CONSTRUCTION', 'ENGINEERING', 'MARINE', 'TRANSPORT', 'CARGO', 'AVIATION', 'CREDIT', 'SURETY', 'AGRICULTURE', 'LIVESTOCK',
        'ASSISTANCE', 'LEGAL_PROTECTION', 'FINANCIAL_LOSS', 'LIFE', 'DEATH', 'SAVINGS', 'CAPITALIZATION', 'RETIREMENT', 'EDUCATION', 'CREDIT_LIFE',
        'GROUP_LIFE', 'PROVIDENT', 'FUNERAL', 'ANNUITY'];

    private static function in(array $values): string
    {
        return "'".implode("','", $values)."'";
    }

    public function up(): void
    {
        Schema::create('product_families', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 64)->unique();
            $t->string('class_code', 40);                                   // PRE §17 normalized class
            $t->foreignUuid('insurance_class_id')->nullable()->constrained('insurance_classes');
            $t->string('line_code', 32)->nullable();
            $t->foreign('line_code')->references('code')->on('insurance_lines');
            $t->string('default_branch_code', 64)->nullable();              // CIMA branch (versioned dictionary, resolved by code)
            $t->jsonb('name');
            $t->jsonb('description')->default('{}');
            $t->string('status', 24)->default('ACTIVE');
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE product_families ADD CONSTRAINT product_families_class_allowed CHECK (class_code IN ('.self::in(self::CLASS_CODES).'))');
        DB::statement("ALTER TABLE product_families ADD CONSTRAINT product_families_status_allowed CHECK (status IN ('ACTIVE','RETIRED'))");

        Schema::create('carrier_products', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_id')->constrained();
            $t->foreignUuid('product_family_id')->nullable()->constrained('product_families');
            $t->string('code', 64);
            $t->string('line_code', 32);
            $t->jsonb('name');                                              // {en, fr}
            $t->jsonb('description')->default('{}');                        // {en, fr}
            $t->string('customer_type', 24)->nullable();
            $t->char('currency', 3)->default('XAF');
            $t->string('market', 32)->nullable();
            $t->string('status', 24)->default('ACTIVE');
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->unique(['carrier_id', 'code']);
        });
        DB::statement("ALTER TABLE carrier_products ADD CONSTRAINT carrier_products_customer_type_allowed CHECK (customer_type IS NULL OR customer_type IN ('INDIVIDUAL','FAMILY','SME','CORPORATE','GROUP','GOVERNMENT','ASSOCIATION'))");
        DB::statement("ALTER TABLE carrier_products ADD CONSTRAINT carrier_products_status_allowed CHECK (status IN ('ACTIVE','SUSPENDED','RETIRED'))");

        Schema::table('insurance_products', function (Blueprint $t) {
            $t->foreignUuid('carrier_product_id')->nullable()->constrained('carrier_products');
            $t->foreignUuid('base_version_id')->nullable()->constrained('insurance_products');
            $t->date('sales_start')->nullable();
            $t->date('sales_end')->nullable();
            $t->boolean('new_business_allowed')->default(true);
            $t->boolean('renewal_allowed')->default(true);
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('suspended_at')->nullable();
            $t->text('suspension_reason')->nullable();
            $t->timestampTz('retired_at')->nullable();
            $t->jsonb('snapshot')->nullable();
            $t->char('snapshot_hash', 64)->nullable();
        });
        DB::statement('ALTER TABLE insurance_products DROP CONSTRAINT IF EXISTS insurance_product_status_allowed');
        DB::statement("ALTER TABLE insurance_products ADD CONSTRAINT insurance_product_status_allowed CHECK (status IN ('DRAFT','IN_REVIEW','APPROVED','ACTIVE','SUSPENDED','RETIRED','REJECTED'))");
        DB::statement('ALTER TABLE insurance_products ADD CONSTRAINT insurance_products_sales_window CHECK (sales_end IS NULL OR sales_start IS NULL OR sales_end >= sales_start)');

        // Backfill one carrier product per (carrier, code), named after its latest version.
        DB::statement(<<<'SQL'
            INSERT INTO carrier_products (id, carrier_id, code, line_code, name, description, currency, status, created_at, updated_at)
            SELECT gen_random_uuid(), p.carrier_id, p.code, p.line_code, jsonb_build_object('en', p.name, 'fr', p.name), '{}'::jsonb, 'XAF', 'ACTIVE', now(), now()
            FROM (SELECT DISTINCT ON (carrier_id, code) carrier_id, code, line_code, name FROM insurance_products ORDER BY carrier_id, code, version DESC) p
        SQL);
        DB::statement('UPDATE insurance_products ip SET carrier_product_id = cp.id FROM carrier_products cp WHERE cp.carrier_id = ip.carrier_id AND cp.code = ip.code');

        // Versions are never edited live: identity/terms columns are frozen once a version leaves DRAFT.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION product_version_freeze() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'DRAFT' AND (
                    NEW.carrier_id IS DISTINCT FROM OLD.carrier_id OR NEW.code IS DISTINCT FROM OLD.code OR NEW.line_code IS DISTINCT FROM OLD.line_code
                    OR NEW.version IS DISTINCT FROM OLD.version OR NEW.name IS DISTINCT FROM OLD.name OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
                    OR NEW.coverages IS DISTINCT FROM OLD.coverages OR NEW.eligibility_rules IS DISTINCT FROM OLD.eligibility_rules
                    OR NEW.carrier_product_id IS DISTINCT FROM OLD.carrier_product_id
                    OR (OLD.snapshot_hash IS NOT NULL AND NEW.snapshot_hash IS DISTINCT FROM OLD.snapshot_hash)
                ) THEN
                    RAISE EXCEPTION 'PRODUCT_VERSION_FROZEN: version % is % and cannot be edited; create a new version', OLD.id, OLD.status;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER insurance_products_version_freeze BEFORE UPDATE ON insurance_products
                FOR EACH ROW EXECUTE FUNCTION product_version_freeze();
        SQL);

        Schema::create('product_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('insurance_product_id')->constrained('insurance_products')->cascadeOnDelete();
            $t->string('code', 32);
            $t->jsonb('name');
            $t->jsonb('description')->default('{}');
            $t->string('tier', 24)->nullable();
            $t->foreignUuid('tariff_version_id')->nullable()->constrained('tariff_versions');
            $t->string('pricing_reference', 120)->nullable();
            $t->jsonb('eligibility')->default('{}');
            $t->boolean('is_default')->default(false);
            $t->unsignedSmallInteger('display_order')->default(0);
            $t->string('status', 24)->default('ACTIVE');
            $t->timestampsTz();
            $t->unique(['insurance_product_id', 'code']);
        });
        DB::statement("ALTER TABLE product_plans ADD CONSTRAINT product_plans_tier_allowed CHECK (tier IS NULL OR tier IN ('TP','TP_PLUS','INTERMEDIATE','COMPREHENSIVE','BRONZE','SILVER','GOLD','PLATINUM','CUSTOM'))");
        DB::statement("ALTER TABLE product_plans ADD CONSTRAINT product_plans_status_allowed CHECK (status IN ('ACTIVE','RETIRED'))");
        DB::statement('CREATE UNIQUE INDEX product_plans_one_default ON product_plans (insurance_product_id) WHERE is_default');

        Schema::create('product_plan_coverages', function (Blueprint $t) {
            $t->foreignUuid('product_plan_id')->constrained('product_plans')->cascadeOnDelete();
            $t->foreignUuid('coverage_definition_id')->constrained('coverage_definitions');
            $t->string('inclusion', 16);
            $t->unsignedSmallInteger('display_order')->default(0);
            $t->primary(['product_plan_id', 'coverage_definition_id']);
        });
        DB::statement("ALTER TABLE product_plan_coverages ADD CONSTRAINT ppc_inclusion_allowed CHECK (inclusion IN ('MANDATORY','OPTIONAL','DEFAULT'))");

        Schema::table('product_coverages', function (Blueprint $t) {
            $t->string('inclusion', 16)->nullable();
            $t->unsignedInteger('waiting_period_days')->nullable();
            $t->string('territory', 64)->nullable();
            $t->string('coverage_period', 32)->nullable();
        });
        DB::statement("UPDATE product_coverages SET inclusion = CASE WHEN is_optional THEN 'OPTIONAL' ELSE 'MANDATORY' END");
        DB::statement("ALTER TABLE product_coverages ALTER COLUMN inclusion SET DEFAULT 'MANDATORY'");
        DB::statement('ALTER TABLE product_coverages ALTER COLUMN inclusion SET NOT NULL');
        DB::statement("ALTER TABLE product_coverages ADD CONSTRAINT product_coverages_inclusion_allowed CHECK (inclusion IN ('MANDATORY','OPTIONAL','DEFAULT'))");

        $limitTypes = ['UNLIMITED', 'FIXED_AMOUNT', 'PERCENT_OF_SUM_INSURED', 'PERCENT_OF_LOSS', 'PER_EVENT', 'PER_PERSON', 'PER_YEAR', 'PER_POLICY_PERIOD', 'AGGREGATE', 'SUB_LIMIT'];
        Schema::create('coverage_limits', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('insurance_product_id')->constrained('insurance_products')->cascadeOnDelete();
            $t->foreignUuid('coverage_definition_id')->constrained('coverage_definitions');
            $t->foreignUuid('product_plan_id')->nullable()->constrained('product_plans')->cascadeOnDelete();
            $t->string('limit_type', 32);
            $t->bigInteger('amount_minor')->nullable();
            $t->unsignedInteger('percentage_bp')->nullable();              // basis points: 500 = 5%
            $t->char('currency', 3)->default('XAF');
            $t->string('notes', 500)->nullable();
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE coverage_limits ADD CONSTRAINT coverage_limits_type_allowed CHECK (limit_type IN ('.self::in($limitTypes).'))');
        DB::statement(<<<'SQL'
            ALTER TABLE coverage_limits ADD CONSTRAINT coverage_limits_shape CHECK (
                (limit_type = 'UNLIMITED' AND amount_minor IS NULL AND percentage_bp IS NULL)
                OR (limit_type IN ('PERCENT_OF_SUM_INSURED','PERCENT_OF_LOSS') AND percentage_bp IS NOT NULL AND percentage_bp <= 10000)
                OR (limit_type NOT IN ('UNLIMITED','PERCENT_OF_SUM_INSURED','PERCENT_OF_LOSS') AND amount_minor IS NOT NULL AND amount_minor >= 0)
            )
        SQL);
        DB::statement("CREATE UNIQUE INDEX coverage_limits_unique ON coverage_limits (insurance_product_id, coverage_definition_id, COALESCE(product_plan_id, '00000000-0000-0000-0000-000000000000'::uuid), limit_type)");

        Schema::create('coverage_deductibles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('insurance_product_id')->constrained('insurance_products')->cascadeOnDelete();
            $t->foreignUuid('coverage_definition_id')->constrained('coverage_definitions');
            $t->foreignUuid('product_plan_id')->nullable()->constrained('product_plans')->cascadeOnDelete();
            $t->string('deductible_type', 16);
            $t->bigInteger('amount_minor')->nullable();
            $t->unsignedInteger('percentage_bp')->nullable();
            $t->string('percentage_basis', 16)->default('LOSS');           // LOSS | SUM_INSURED
            $t->unsignedInteger('days')->nullable();
            $t->bigInteger('minimum_minor')->nullable();
            $t->bigInteger('maximum_minor')->nullable();
            $t->char('currency', 3)->default('XAF');
            $t->string('notes', 500)->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE coverage_deductibles ADD CONSTRAINT coverage_deductibles_type_allowed CHECK (deductible_type IN ('FIXED','PERCENTAGE','DAYS','COMBINED','MINIMUM','MAXIMUM'))");
        DB::statement("ALTER TABLE coverage_deductibles ADD CONSTRAINT coverage_deductibles_basis_allowed CHECK (percentage_basis IN ('LOSS','SUM_INSURED'))");
        DB::statement(<<<'SQL'
            ALTER TABLE coverage_deductibles ADD CONSTRAINT coverage_deductibles_shape CHECK (
                (deductible_type = 'FIXED' AND amount_minor IS NOT NULL)
                OR (deductible_type = 'PERCENTAGE' AND percentage_bp IS NOT NULL)
                OR (deductible_type = 'DAYS' AND days IS NOT NULL)
                OR (deductible_type = 'COMBINED' AND percentage_bp IS NOT NULL AND (minimum_minor IS NOT NULL OR maximum_minor IS NOT NULL))
                OR (deductible_type = 'MINIMUM' AND minimum_minor IS NOT NULL)
                OR (deductible_type = 'MAXIMUM' AND maximum_minor IS NOT NULL)
            )
        SQL);
        DB::statement('ALTER TABLE coverage_deductibles ADD CONSTRAINT coverage_deductibles_min_max CHECK (minimum_minor IS NULL OR maximum_minor IS NULL OR maximum_minor >= minimum_minor)');
        DB::statement("CREATE UNIQUE INDEX coverage_deductibles_unique ON coverage_deductibles (insurance_product_id, coverage_definition_id, COALESCE(product_plan_id, '00000000-0000-0000-0000-000000000000'::uuid), deductible_type)");

        // Consolidate the legacy single-int defaults into the typed tables (columns kept for compatibility).
        DB::statement(<<<'SQL'
            INSERT INTO coverage_limits (id, insurance_product_id, coverage_definition_id, limit_type, amount_minor, notes, created_at, updated_at)
            SELECT gen_random_uuid(), insurance_product_id, coverage_definition_id, 'FIXED_AMOUNT', default_limit_minor, 'Migrated from product_coverages.default_limit_minor', now(), now()
            FROM product_coverages WHERE default_limit_minor IS NOT NULL
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO coverage_deductibles (id, insurance_product_id, coverage_definition_id, deductible_type, amount_minor, notes, created_at, updated_at)
            SELECT gen_random_uuid(), insurance_product_id, coverage_definition_id, 'FIXED', default_deductible_minor, 'Migrated from product_coverages.default_deductible_minor', now(), now()
            FROM product_coverages WHERE default_deductible_minor IS NOT NULL
        SQL);

        // REQ-PRD-006 exclusions: levels + extensions + versioned legal text.
        Schema::table('exclusion_definitions', function (Blueprint $t) {
            $t->string('kind', 16)->default('EXCLUSION');
            $t->jsonb('effects')->default('{}');                            // extensions: premium / limit / eligibility / underwriting effects
        });
        DB::statement("ALTER TABLE exclusion_definitions ADD CONSTRAINT exclusion_definitions_kind_allowed CHECK (kind IN ('EXCLUSION','EXTENSION'))");

        DB::statement('ALTER TABLE product_exclusions DROP CONSTRAINT IF EXISTS product_exclusions_pkey');
        Schema::table('product_exclusions', function (Blueprint $t) {
            $t->uuid('id')->default(DB::raw('gen_random_uuid()'));
            $t->string('level', 16)->default('PRODUCT');
            $t->foreignUuid('product_plan_id')->nullable()->constrained('product_plans')->cascadeOnDelete();
            $t->foreignUuid('coverage_definition_id')->nullable()->constrained('coverage_definitions');
            $t->jsonb('condition')->default('{}');
        });
        DB::statement('ALTER TABLE product_exclusions ADD PRIMARY KEY (id)');
        DB::statement("ALTER TABLE product_exclusions ADD CONSTRAINT product_exclusions_level_allowed CHECK (level IN ('PRODUCT','PLAN','COVERAGE','CUSTOMER','RISK','CLAIM'))");
        DB::statement(<<<'SQL'
            ALTER TABLE product_exclusions ADD CONSTRAINT product_exclusions_level_shape CHECK (
                (level = 'PLAN' AND product_plan_id IS NOT NULL) OR (level = 'COVERAGE' AND coverage_definition_id IS NOT NULL)
                OR level IN ('PRODUCT','CUSTOMER','RISK','CLAIM')
            )
        SQL);
        DB::statement("CREATE UNIQUE INDEX product_exclusions_unique ON product_exclusions (insurance_product_id, exclusion_definition_id, level, COALESCE(product_plan_id, '00000000-0000-0000-0000-000000000000'::uuid), COALESCE(coverage_definition_id, '00000000-0000-0000-0000-000000000000'::uuid))");

        Schema::create('exclusion_legal_texts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('exclusion_definition_id')->constrained('exclusion_definitions');
            $t->unsignedInteger('version');
            $t->jsonb('text');                                              // {en, fr}
            $t->string('legal_reference', 255)->nullable();
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('status', 24)->default('DRAFT');
            $t->char('text_hash', 64);
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('recorded_at')->useCurrent();
            $t->timestampTz('superseded_at')->nullable();
            $t->timestampsTz();
            $t->unique(['exclusion_definition_id', 'version']);
        });
        DB::statement("ALTER TABLE exclusion_legal_texts ADD CONSTRAINT exclusion_legal_texts_status_allowed CHECK (status IN ('DRAFT','APPROVED','RETIRED','REJECTED'))");
        DB::statement('ALTER TABLE exclusion_legal_texts ADD CONSTRAINT exclusion_legal_texts_range CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exclusion_legal_text_freeze() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'DRAFT' AND (NEW.text IS DISTINCT FROM OLD.text OR NEW.text_hash IS DISTINCT FROM OLD.text_hash
                    OR NEW.effective_from IS DISTINCT FROM OLD.effective_from OR NEW.version IS DISTINCT FROM OLD.version) THEN
                    RAISE EXCEPTION 'LEGAL_TEXT_FROZEN: legal text % is % and cannot be edited', OLD.id, OLD.status;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER exclusion_legal_texts_freeze BEFORE UPDATE ON exclusion_legal_texts FOR EACH ROW EXECUTE FUNCTION exclusion_legal_text_freeze();
        SQL);

        $now = now();
        DB::table('versioned_artifact_registry')->insert([
            'artifact_type' => 'exclusion_legal_text', 'source_table' => 'exclusion_legal_texts', 'key_columns' => json_encode(['exclusion_definition_id']),
            'from_column' => 'effective_from', 'until_column' => 'effective_until', 'granularity' => 'DAY', 'status_column' => 'status',
            'effective_statuses' => json_encode(['APPROVED']), 'version_column' => 'version', 'rule_category' => 'TERMS', 'bitemporal' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::statement(<<<'SQL'
            CREATE VIEW product_versions AS
            SELECT ip.id, ip.id AS insurance_product_id, ip.carrier_product_id, ip.carrier_id, ip.code AS product_code, ip.version AS version_number,
                   ip.effective_from, ip.effective_until, ip.sales_start, ip.sales_end, ip.new_business_allowed, ip.renewal_allowed,
                   CASE ip.status WHEN 'IN_REVIEW' THEN 'REVIEW' WHEN 'ACTIVE' THEN 'PUBLISHED' ELSE ip.status END AS status,
                   ip.status AS storage_status, ip.approved_by, ip.approved_at, ip.published_by, ip.published_at, ip.suspended_at, ip.retired_at,
                   ip.base_version_id, ip.snapshot_hash, ip.created_at, ip.updated_at
            FROM insurance_products ip
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS product_versions');
        DB::table('versioned_artifact_registry')->where('artifact_type', 'exclusion_legal_text')->delete();
        DB::unprepared('DROP TRIGGER IF EXISTS exclusion_legal_texts_freeze ON exclusion_legal_texts; DROP FUNCTION IF EXISTS exclusion_legal_text_freeze();');
        Schema::dropIfExists('exclusion_legal_texts');
        DB::statement('DROP INDEX IF EXISTS product_exclusions_unique');
        DB::statement('ALTER TABLE product_exclusions DROP CONSTRAINT IF EXISTS product_exclusions_pkey');
        DB::statement("DELETE FROM product_exclusions WHERE level <> 'PRODUCT'");
        Schema::table('product_exclusions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('product_plan_id');
            $t->dropConstrainedForeignId('coverage_definition_id');
            $t->dropColumn(['id', 'level', 'condition']);
        });
        DB::statement('ALTER TABLE product_exclusions ADD PRIMARY KEY (insurance_product_id, exclusion_definition_id)');
        DB::statement('ALTER TABLE exclusion_definitions DROP CONSTRAINT IF EXISTS exclusion_definitions_kind_allowed');
        Schema::table('exclusion_definitions', fn (Blueprint $t) => $t->dropColumn(['kind', 'effects']));
        Schema::dropIfExists('coverage_deductibles');
        Schema::dropIfExists('coverage_limits');
        DB::statement('ALTER TABLE product_coverages DROP CONSTRAINT IF EXISTS product_coverages_inclusion_allowed');
        Schema::table('product_coverages', fn (Blueprint $t) => $t->dropColumn(['inclusion', 'waiting_period_days', 'territory', 'coverage_period']));
        Schema::dropIfExists('product_plan_coverages');
        Schema::dropIfExists('product_plans');
        DB::unprepared('DROP TRIGGER IF EXISTS insurance_products_version_freeze ON insurance_products; DROP FUNCTION IF EXISTS product_version_freeze();');
        DB::statement('ALTER TABLE insurance_products DROP CONSTRAINT IF EXISTS insurance_products_sales_window');
        DB::statement('ALTER TABLE insurance_products DROP CONSTRAINT IF EXISTS insurance_product_status_allowed');
        DB::statement("ALTER TABLE insurance_products ADD CONSTRAINT insurance_product_status_allowed CHECK (status IN ('DRAFT','IN_REVIEW','ACTIVE','RETIRED','REJECTED'))");
        Schema::table('insurance_products', function (Blueprint $t) {
            $t->dropConstrainedForeignId('carrier_product_id');
            $t->dropConstrainedForeignId('base_version_id');
            $t->dropConstrainedForeignId('approved_by');
            $t->dropColumn(['sales_start', 'sales_end', 'new_business_allowed', 'renewal_allowed', 'approved_at', 'suspended_at', 'suspension_reason', 'retired_at', 'snapshot', 'snapshot_hash']);
        });
        Schema::dropIfExists('carrier_products');
        Schema::dropIfExists('product_families');
    }
};
