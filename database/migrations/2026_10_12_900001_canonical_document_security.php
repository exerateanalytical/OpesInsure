<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical document security (docs/spec/canonical/OpesInsure_Canonical_Implementation_Specification_v1.json).
 * Additive only; extends the existing document catalogue and engine (no parallel registry):
 *
 *  - document_canonical_specs: the 220 canonical document records keyed by their stable spec id
 *    (DOC-001..DOC-220), with field requirements, detailed field specs, raw + normalized security
 *    profile and master shells, and their mapping onto document_types (the catalogue keeps its own
 *    type_id numbering; see DOCUMENT_SPEC_GAP_AUDIT.md "ID reconciliation").
 *  - document_spec_dictionary: tiers S1-S5, A4 zones, field groups, security control legend,
 *    confidentiality classes, access profiles, shared security artifacts, master shells.
 *  - document_types gains the applied security profile (tier floor/ceiling, controls,
 *    confidentiality, access profiles, master shell). A trigger refuses any tier downgrade.
 *  - documents gains the issuance snapshot, content/snapshot hashes, applied security controls,
 *    verification token hash and signature. A trigger keeps issued originals immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_canonical_specs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('spec_id', 16)->unique(); // DOC-001..DOC-220, never renumbered
            $t->string('name_en');
            $t->string('name_fr');
            $t->char('category_code', 1);
            $t->string('category_name');
            $t->string('typical_security_tier', 16);
            $t->string('tier_floor', 4);
            $t->string('tier_ceiling', 4);
            $t->jsonb('field_group_refs');          // explicit + derived, with method
            $t->text('minimum_additions')->nullable();
            $t->jsonb('detailed_field_spec')->nullable();
            $t->jsonb('detailed_field_map')->nullable(); // bullet -> canonical key / status
            $t->jsonb('security_profile_raw');
            $t->jsonb('security_controls');         // normalized control => {requirement, variant, raw}
            $t->jsonb('confidentiality_classes');
            $t->jsonb('access_profiles');
            $t->jsonb('master_shell_codes');
            $t->jsonb('catalogue_type_ids');        // document_types.type_id mapped by exact name
            $t->string('mapping_status', 32);       // MAPPED | PENDING_VERIFICATION
            $t->string('spec_version', 32);
            $t->string('source_hash', 64);
            $t->string('source_reference', 500);
            $t->string('status', 24)->default('ACTIVE');
            $t->boolean('is_seeded')->default(true);
            $t->timestampsTz();
        });

        Schema::create('document_spec_dictionary', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // SECURITY_TIER | A4_ZONE | FIELD_GROUP | SECURITY_CONTROL | CONFIDENTIALITY_CLASS | ACCESS_PROFILE | SECURITY_ARTIFACT | MASTER_SHELL | INVARIANT
            $t->string('kind', 32);
            $t->string('code', 80);
            $t->string('name', 255)->nullable();
            $t->jsonb('payload');
            $t->unsignedSmallInteger('rank')->nullable();
            $t->string('spec_version', 32);
            $t->string('status', 24)->default('ACTIVE');
            $t->boolean('is_seeded')->default(true);
            $t->timestampsTz();
            $t->unique(['kind', 'code']);
        });

        Schema::table('document_types', function (Blueprint $t) {
            $t->string('canonical_spec_id', 16)->nullable()->index();
            $t->string('security_tier', 4)->nullable();
            $t->string('security_tier_ceiling', 4)->nullable();
            $t->jsonb('security_controls')->nullable();
            $t->string('confidentiality_class', 32)->nullable();
            $t->jsonb('access_profiles')->nullable();
            $t->string('master_shell_code', 64)->nullable();
        });

        Schema::table('documents', function (Blueprint $t) {
            $t->string('security_tier', 4)->nullable();
            $t->jsonb('security_controls')->nullable();
            $t->string('confidentiality_class', 32)->nullable();
            $t->jsonb('access_profiles')->nullable();
            $t->string('master_shell_code', 64)->nullable();
            $t->jsonb('issuance_snapshot')->nullable();
            $t->char('snapshot_hash', 64)->nullable();
            $t->char('content_hash_sha256', 64)->nullable();
            $t->char('verification_token_hash', 64)->nullable()->unique();
            $t->jsonb('signature')->nullable();
            $t->jsonb('field_validation')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['document_canonical_specs', 'document_spec_dictionary'] as $table) {
            // Same protection as the rest of the catalogue: seeded rows are deactivated, never deleted.
            DB::unprepared("CREATE TRIGGER {$table}_protect_seeded BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_seeded_document_catalogue_delete();");
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_security_tier_rank(t text) RETURNS int AS $$
                SELECT CASE WHEN t ~ '^S[1-5]$' THEN substring(t from 2)::int ELSE 0 END;
            $$ LANGUAGE sql IMMUTABLE;

            CREATE OR REPLACE FUNCTION prevent_document_type_security_downgrade() RETURNS trigger AS $$
            BEGIN
                IF OLD.security_tier IS NOT NULL AND document_security_tier_rank(NEW.security_tier) < document_security_tier_rank(OLD.security_tier) THEN
                    RAISE EXCEPTION 'Document type % security tier cannot be downgraded (% -> %)', OLD.type_id, OLD.security_tier, NEW.security_tier;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER document_types_no_security_downgrade BEFORE UPDATE ON document_types
                FOR EACH ROW EXECUTE FUNCTION prevent_document_type_security_downgrade();

            CREATE OR REPLACE FUNCTION prevent_issued_document_mutation() RETURNS trigger AS $$
            BEGIN
                IF OLD.content_hash_sha256 IS NOT NULL AND (
                    NEW.sha256 IS DISTINCT FROM OLD.sha256 OR NEW.storage_key IS DISTINCT FROM OLD.storage_key
                    OR NEW.document_number IS DISTINCT FROM OLD.document_number OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
                    OR NEW.issuance_snapshot IS DISTINCT FROM OLD.issuance_snapshot OR NEW.snapshot_hash IS DISTINCT FROM OLD.snapshot_hash
                    OR NEW.content_hash_sha256 IS DISTINCT FROM OLD.content_hash_sha256 OR NEW.template_hash IS DISTINCT FROM OLD.template_hash
                    OR NEW.verification_code IS DISTINCT FROM OLD.verification_code OR NEW.verification_token_hash IS DISTINCT FROM OLD.verification_token_hash
                    OR NEW.security_tier IS DISTINCT FROM OLD.security_tier OR NEW.security_controls IS DISTINCT FROM OLD.security_controls
                    OR NEW.signature IS DISTINCT FROM OLD.signature
                ) THEN
                    RAISE EXCEPTION 'Issued document % is an immutable snapshot; issue a replacement instead', OLD.id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER documents_issued_immutable BEFORE UPDATE ON documents
                FOR EACH ROW EXECUTE FUNCTION prevent_issued_document_mutation();

            CREATE OR REPLACE FUNCTION prevent_issued_document_delete() RETURNS trigger AS $$
            BEGIN
                IF OLD.content_hash_sha256 IS NOT NULL THEN
                    RAISE EXCEPTION 'Issued document % cannot be deleted; revoke or replace it', OLD.id;
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER documents_issued_no_delete BEFORE DELETE ON documents
                FOR EACH ROW EXECUTE FUNCTION prevent_issued_document_delete();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS documents_issued_no_delete ON documents; DROP FUNCTION IF EXISTS prevent_issued_document_delete();');
            DB::unprepared('DROP TRIGGER IF EXISTS documents_issued_immutable ON documents; DROP FUNCTION IF EXISTS prevent_issued_document_mutation();');
            DB::unprepared('DROP TRIGGER IF EXISTS document_types_no_security_downgrade ON document_types; DROP FUNCTION IF EXISTS prevent_document_type_security_downgrade();');
            DB::unprepared('DROP FUNCTION IF EXISTS document_security_tier_rank(text);');
            foreach (['document_canonical_specs', 'document_spec_dictionary'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_protect_seeded ON {$table};");
            }
        }
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn(['security_tier', 'security_controls', 'confidentiality_class', 'access_profiles', 'master_shell_code', 'issuance_snapshot', 'snapshot_hash', 'content_hash_sha256', 'verification_token_hash', 'signature', 'field_validation']));
        Schema::table('document_types', fn (Blueprint $t) => $t->dropColumn(['canonical_spec_id', 'security_tier', 'security_tier_ceiling', 'security_controls', 'confidentiality_class', 'access_profiles', 'master_shell_code']));
        Schema::dropIfExists('document_spec_dictionary');
        Schema::dropIfExists('document_canonical_specs');
    }
};
