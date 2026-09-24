<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical Insurance Document Type Registry, Document Packs and the Document
 * Requirement Matrix (docs/spec/DOCUMENT_CATALOGUE_BY_POLICY_TYPE_V1.md,
 * docs/spec/DOCUMENT_REQUIREMENT_MATRIX_V1.md, owner register
 * database/data/document_register_220_2026.json).
 *
 * Purely additive. Rows loaded from database/data/document_catalogue_2026.json
 * and document_requirement_matrix_2026.json carry is_seeded = true and cannot be
 * deleted (PostgreSQL BEFORE DELETE trigger, mirrored by
 * App\Models\DocumentCatalogue\Concerns\ProtectsSeededCatalogueData): they are
 * deactivated (status / effective_until), never removed. Insurer adaptations
 * live in product_document_requirements (maker-checker, retired not deleted).
 * Existing tables (documents, document_versions, certificate_templates,
 * document_requirement_versions) are untouched; the document engine joins to
 * this registry through document_types.type_id / canonical_code (same ids as documents.document_type_id).
 */
return new class extends Migration
{
    public const PROTECTED = [
        'document_types', 'document_packs', 'document_pack_items', 'document_type_class_applicability',
        'document_product_types', 'document_requirement_matrix', 'document_matrix_variants',
    ];

    public function up(): void
    {
        $versioned = function (Blueprint $t): void {
            $t->string('catalogue_version', 32);
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('source_reference', 500)->nullable();
            $t->string('status', 24)->default('ACTIVE');
            $t->boolean('is_seeded')->default(false);
            $t->timestampsTz();
        };

        Schema::create('document_types', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            // Stable owner id: DOC-001..DOC-220, CLM-01..30, FAMILY.CODE subtypes (same ids as documents.document_type_id), EVD-nnn
            $t->string('type_id', 64)->unique();
            $t->string('canonical_code', 96);
            // REGISTER | CLAIM | FINANCE | REINSURANCE | COINSURANCE | PROVIDER_NETWORK | REGULATORY_COMPLIANCE | EVIDENCE
            $t->string('namespace', 32);
            // TYPE | FAMILY | SUBTYPE | EVIDENCE
            $t->string('kind', 16);
            $t->string('parent_type_id', 64)->nullable()->index();
            $t->string('same_as_type_id', 64)->nullable();
            $t->string('name_en');
            $t->string('name_fr');
            $t->char('register_group', 1)->nullable();
            $t->string('register_group_code', 32)->nullable();
            $t->string('category', 32)->index();
            $t->string('document_origin', 16);
            $t->jsonb('issuer_authority');
            $t->string('recipient', 32)->nullable();
            $t->jsonb('stages');
            $t->string('audience', 24);
            $t->string('legal_reference', 64)->nullable();
            $t->boolean('verifiable')->default(false);
            $t->string('security_level', 32);
            $t->string('scope', 16)->default('POLICY');
            $t->boolean('is_evidence')->default(false);
            $t->jsonb('generation_triggers');
            $t->string('numbering_family', 16)->nullable();
            $t->jsonb('aliases');
            $versioned($t);
            $t->unique(['namespace', 'canonical_code']);
            $t->index('canonical_code');
        });

        Schema::create('document_packs', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('code', 64)->unique();
            $t->string('label_en');
            $t->string('label_fr');
            $t->string('lifecycle_stage', 24)->index();
            $t->string('scope', 16)->default('POLICY');
            $t->boolean('is_universal')->default(false);
            $t->string('owner_pack', 64)->nullable();
            $t->jsonb('class_codes');
            $versioned($t);
        });

        Schema::create('document_pack_items', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->foreignUuid('document_pack_id')->constrained('document_packs')->restrictOnDelete();
            $t->string('document_type_id', 64);
            $t->foreign('document_type_id')->references('type_id')->on('document_types')->restrictOnDelete();
            // REQUIRED | CONDITIONAL | PRODUCT_DEPENDENT | WHERE_APPLICABLE | OPTIONAL | INTERNAL | THIRD_PARTY
            $t->string('requirement', 24);
            $t->string('condition_note', 500)->nullable();
            $t->unsignedInteger('sort_order');
            $versioned($t);
            $t->unique(['document_pack_id', 'document_type_id']);
        });

        Schema::create('document_type_class_applicability', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('class_code', 64);
            $t->string('document_type_id', 64);
            $t->foreign('document_type_id')->references('type_id')->on('document_types')->restrictOnDelete();
            $t->string('source', 24)->default('PACK');
            $versioned($t);
            $t->unique(['class_code', 'document_type_id']);
        });

        Schema::create('document_product_types', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('code', 64)->unique();
            $t->string('label_en');
            $t->string('label_fr');
            $t->string('class_code', 64)->nullable();
            $t->string('extends_code', 64)->nullable();
            $t->unsignedSmallInteger('spec_section')->nullable();
            $versioned($t);
        });

        Schema::create('document_matrix_variants', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            $t->string('variant_code', 96)->unique();
            $t->string('matrix_label');
            $t->string('nearest_type_id', 64);
            $t->foreign('nearest_type_id')->references('type_id')->on('document_types')->restrictOnDelete();
            $versioned($t);
        });

        Schema::create('document_requirement_matrix', function (Blueprint $t) use ($versioned) {
            $t->uuid('id')->primary();
            // product type code, or __BASELINE__ for the universal new-business baseline (section 3)
            $t->string('product_type_code', 64)->index();
            // PRE_CONTRACT | ISSUANCE | SERVICING | RENEWAL | TREATMENT | MOVEMENT | LIFECYCLE | CLAIM
            $t->string('stage', 24);
            $t->string('document_type_id', 64);
            $t->foreign('document_type_id')->references('type_id')->on('document_types')->restrictOnDelete();
            $t->string('variant_code', 96)->default('');
            $t->string('matrix_label');
            // M | C | O | I | T
            $t->char('level', 1);
            $t->char('alternate_level', 1)->nullable();
            $t->boolean('insurer_overridable')->default(false);
            $t->string('raw_level', 32);
            $t->jsonb('qualifiers');
            $t->string('issuer', 24)->nullable();
            $t->string('recipient', 32)->nullable();
            $t->string('trigger', 64)->nullable();
            $t->unsignedInteger('sort_order');
            $versioned($t);
            $t->unique(['product_type_code', 'stage', 'document_type_id', 'variant_code'], 'doc_req_matrix_natural_key');
        });

        // Insurer / product-version adaptations. Never deleted: RETIRED or REJECTED.
        Schema::create('product_document_requirements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('insurance_product_id')->constrained('insurance_products')->restrictOnDelete();
            // PRODUCT_TYPE (select matrix product type) | MATRIX_OVERRIDE (level override). Pack add/remove per trigger lives in the document engine's product_document_rules.
            $t->string('kind', 24);
            $t->string('product_type_code', 64)->nullable();
            $t->string('document_type_id', 64)->nullable();
            $t->foreign('document_type_id')->references('type_id')->on('document_types')->restrictOnDelete();
            $t->string('stage', 24)->nullable();
            $t->string('variant_code', 96)->default('');
            $t->char('level', 1)->nullable();
            $t->string('condition_note', 500)->nullable();
            // PENDING_APPROVAL | ACTIVE | REJECTED | RETIRED
            $t->string('status', 24)->default('PENDING_APPROVAL');
            $t->string('reason', 1000)->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->foreignUuid('retired_by')->nullable()->constrained('users');
            $t->timestampTz('retired_at')->nullable();
            $t->timestampsTz();
            $t->index(['insurance_product_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_seeded_document_catalogue_delete() RETURNS trigger AS $$
                BEGIN
                    IF OLD.is_seeded THEN
                        RAISE EXCEPTION 'Seeded document catalogue row %.% cannot be deleted; deactivate it (status / effective_until) instead', TG_TABLE_NAME, OLD.id;
                    END IF;
                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;
                CREATE OR REPLACE FUNCTION prevent_product_document_requirement_delete() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Product document requirement % cannot be deleted; retire it instead', OLD.id;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            foreach (self::PROTECTED as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_protect_seeded BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_seeded_document_catalogue_delete();");
            }
            DB::unprepared('CREATE TRIGGER product_document_requirements_no_delete BEFORE DELETE ON product_document_requirements FOR EACH ROW EXECUTE FUNCTION prevent_product_document_requirement_delete();');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS product_document_requirements_no_delete ON product_document_requirements;');
            foreach (self::PROTECTED as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_protect_seeded ON {$table};");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_product_document_requirement_delete();');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_seeded_document_catalogue_delete();');
        }
        foreach (['product_document_requirements', 'document_requirement_matrix', 'document_matrix_variants', 'document_product_types',
            'document_type_class_applicability', 'document_pack_items', 'document_packs', 'document_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
