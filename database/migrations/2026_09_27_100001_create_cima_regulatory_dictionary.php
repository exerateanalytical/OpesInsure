<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CIMA Regulatory Dictionary (docs/spec/CIMA_REGULATORY_DICTIONARY_V1.md).
 * Purely additive. Every regulatory row is effective-dated and versioned
 * (regulatory_version); rows loaded from database/data/cima_regulatory_master_2026.json
 * carry is_seeded = true and are protected against deletion by a PostgreSQL
 * BEFORE DELETE trigger (mirrored in App\Models\Regulatory\Concerns\ProtectsSeededRegulatoryData).
 */
return new class extends Migration
{
    /** Tables whose seeded rows can never be deleted. */
    public const PROTECTED = [
        'regulatory_regimes', 'regulatory_branches', 'regulatory_branch_subclasses', 'microinsurance_branches',
        'regulatory_reporting_categories', 'regulatory_terms', 'regulatory_term_translations', 'regulatory_authorities',
        'legal_references', 'compulsory_insurance_rules', 'regulatory_class_defaults', 'product_regulatory_mappings',
        'insurer_regulatory_authorizations', 'insurer_authorized_branches',
    ];

    public function up(): void
    {
        $versioned = function (Blueprint $t): void {
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('regulatory_version', 32);
            $t->string('source_reference', 500)->nullable();
            $t->string('status', 24)->default('ACTIVE');
            $t->boolean('is_seeded')->default(false);
            $t->timestamps();
        };

        Schema::create('regulatory_regimes', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('code', 32);
            $t->string('name_fr');
            $t->string('name_en')->nullable();
            $t->string('jurisdiction', 32);
            $t->jsonb('metadata')->nullable();
            $versioned($t);
            $t->unique(['code', 'regulatory_version']);
        });

        Schema::create('regulatory_branches', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('regime', 32);
            $t->unsignedSmallInteger('number');
            $t->string('code', 64);
            $t->string('label_fr');
            $t->string('label_en');
            $t->string('business_family', 16);
            $t->boolean('reserved')->default(false);
            $t->boolean('accessory_allowed')->default(true);
            $t->boolean('complementary_covers_allowed')->default(false);
            $t->boolean('is_compulsory')->default(false);
            $t->string('compulsory_basis', 500)->nullable();
            $t->string('legal_reference', 64)->nullable();
            $versioned($t);
            $t->unique(['code', 'regulatory_version']);
            $t->index(['regime', 'number']);
        });

        Schema::create('regulatory_branch_subclasses', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('regime', 32);
            $t->string('branch_code', 64);
            $t->string('code', 64);
            $t->string('label_fr')->nullable();
            $t->string('label_en')->nullable();
            $t->string('legal_reference', 64)->nullable();
            $versioned($t);
            $t->unique(['code', 'regulatory_version']);
        });

        Schema::create('microinsurance_branches', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('regime', 32);
            $t->unsignedSmallInteger('number');
            $t->string('code', 64);
            $t->string('label_fr');
            $t->string('label_en');
            $t->string('business_family', 16);
            $t->string('legal_reference', 64)->nullable();
            $versioned($t);
            $t->unique(['code', 'regulatory_version']);
        });

        Schema::create('regulatory_reporting_categories', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('regime', 32);
            // ART_411_CATEGORY (insurer reporting) | ART_557_MEASURE (intermediary reporting)
            $t->string('kind', 32);
            $t->string('code', 96);
            $t->unsignedSmallInteger('sequence');
            $t->string('label_fr')->nullable();
            $t->string('label_en')->nullable();
            $t->string('legal_reference', 64)->nullable();
            $versioned($t);
            $t->unique(['kind', 'code', 'regulatory_version']);
        });

        Schema::create('regulatory_terms', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('regime', 32);
            // CIMA_TERM | CIMA_PARTY_ROLE
            $t->string('namespace', 32)->default('CIMA_TERM');
            $t->string('code', 96);
            $t->string('category', 32);
            $t->string('source_article', 64)->nullable();
            $t->boolean('preferred')->default(true);
            $t->text('notes')->nullable();
            $versioned($t);
            $t->unique(['namespace', 'code', 'regulatory_version']);
            $t->index(['regime', 'category']);
        });

        Schema::create('regulatory_term_translations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('regulatory_term_id')->constrained('regulatory_terms')->restrictOnDelete();
            $t->string('locale', 8);
            $t->string('label');
            // PREFERRED | ALTERNATIVE
            $t->string('context', 32)->default('PREFERRED');
            $t->boolean('is_seeded')->default(false);
            $t->timestamps();
            $t->unique(['regulatory_term_id', 'locale', 'label']);
        });

        Schema::create('regulatory_authorities', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('regime', 32);
            $t->string('code', 64);
            $t->string('name_fr');
            $t->string('name_en')->nullable();
            $versioned($t);
            $t->unique(['code', 'regulatory_version']);
        });

        Schema::create('legal_references', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('regime', 32);
            $t->string('reference', 64);
            $t->string('title')->nullable();
            $t->text('summary')->nullable();
            $versioned($t);
            $t->unique(['regime', 'reference', 'regulatory_version']);
        });

        Schema::create('compulsory_insurance_rules', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('regime', 32);
            $t->string('code', 96);
            $t->string('branch_code', 64);
            $t->string('basis', 500);
            $t->string('jurisdiction', 32);
            $t->string('legal_reference', 64)->nullable();
            $versioned($t);
            $t->unique(['code', 'regulatory_version']);
        });

        // Default class → CIMA branch mapping (owner: mapping is automatic in the background, overridable per product).
        Schema::create('regulatory_class_defaults', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('regime', 32);
            $t->string('line_code', 32);
            $t->string('branch_code', 64);
            $t->string('relationship_type', 16);
            // Only applied when the product carries this coverage (null = always).
            $t->string('requires_coverage_code', 64)->nullable();
            $versioned($t);
            $t->unique(['line_code', 'branch_code', 'relationship_type', 'regulatory_version'], 'reg_class_defaults_unique');
        });

        Schema::create('insurer_regulatory_authorizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_id')->constrained('carriers')->restrictOnDelete();
            $t->string('jurisdiction', 8)->default('CM');
            $t->string('regime', 32)->default('CIMA');
            // PENDING_APPROVAL | ACTIVE | SUSPENDED | REVOKED | REJECTED
            $t->string('status', 24);
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('authorization_reference', 160);
            // REGULATOR_DECREE | REGULATOR_LETTER | OFFICIAL_GAZETTE | DEMO | ...
            $t->string('source', 64);
            $t->string('source_document', 500)->nullable();
            $t->boolean('is_demo')->default(false);
            $t->text('notes')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->boolean('is_seeded')->default(false);
            $t->timestamps();
            $t->index(['carrier_id', 'status']);
        });

        Schema::create('insurer_authorized_branches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('authorization_id')->constrained('insurer_regulatory_authorizations')->restrictOnDelete();
            $t->string('branch_code', 64);
            $t->string('status', 24)->default('ACTIVE');
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->boolean('is_seeded')->default(false);
            $t->timestamps();
            $t->unique(['authorization_id', 'branch_code']);
        });

        Schema::create('product_regulatory_mappings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('insurance_product_id')->constrained('insurance_products')->restrictOnDelete();
            $t->unsignedInteger('product_version');
            $t->string('regime', 32)->default('CIMA');
            $t->string('branch_code', 64);
            // PRIMARY | ACCESSORY | COMPLEMENTARY
            $t->string('relationship_type', 16);
            $t->boolean('is_primary')->default(false);
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('legal_reference', 64)->nullable();
            // PENDING_APPROVAL | ACTIVE | SUPERSEDED | REJECTED
            $t->string('status', 24);
            // CLASS_DEFAULT (automatic) | ADMIN
            $t->string('source', 24);
            $t->text('notes')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->boolean('is_seeded')->default(false);
            $t->timestamps();
            $t->index(['insurance_product_id', 'status']);
        });

        // PLT-CIMA-012: normalized class / product → Article 411 reporting category.
        Schema::create('regulatory_reporting_mappings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('regime', 32)->default('CIMA');
            // INSURANCE_LINE | INSURANCE_PRODUCT
            $t->string('subject_type', 32);
            $t->string('subject_code', 96);
            $t->string('reporting_category_code', 96);
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('status', 24)->default('ACTIVE');
            $t->text('notes')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['subject_type', 'subject_code']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_seeded_regulatory_delete() RETURNS trigger AS $$
                BEGIN
                    IF OLD.is_seeded THEN
                        RAISE EXCEPTION 'Seeded CIMA regulatory row %.% cannot be deleted; close it with effective_until instead', TG_TABLE_NAME, OLD.id;
                    END IF;
                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            foreach (self::PROTECTED as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_protect_seeded BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_seeded_regulatory_delete();");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::PROTECTED as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_protect_seeded ON {$table};");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_seeded_regulatory_delete();');
        }
        foreach (['regulatory_reporting_mappings', 'product_regulatory_mappings', 'insurer_authorized_branches', 'insurer_regulatory_authorizations',
            'regulatory_class_defaults', 'compulsory_insurance_rules', 'legal_references', 'regulatory_authorities', 'regulatory_term_translations',
            'regulatory_terms', 'regulatory_reporting_categories', 'microinsurance_branches', 'regulatory_branch_subclasses', 'regulatory_branches',
            'regulatory_regimes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
