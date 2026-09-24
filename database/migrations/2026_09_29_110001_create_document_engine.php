<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Document engine (DOCUMENT_CATALOGUE_BY_POLICY_TYPE_V1 + the 220-type
 | Canonical Document Register + ADAPTIVE_OPERATING_MODEL_V1 carrier
 | original documents). Additive only:
 |  - documents gains the generated/issued-document record (type, pack,
 |    template version, product/policy version, issuer, language, origin,
 |    stage, security level, status + replacement links, continuous number,
 |    verification code, provenance);
 |  - document_templates: PLATFORM | INSURER | BROKER | REGULATORY templates
 |    with the DRAFT → REVIEW → APPROVED → PUBLISHED → RETIRED workflow;
 |  - numbering families + gap-free counters, issuance profiles (issuer
 |    authority / signature / QR per insurer or product), pack manifests
 |    (generated pack instances), maker-checker status changes. Packs and
 |    requirements are read from the document catalogue tables (REQ-DUP-004).
 | Issued documents are never deleted: status + links only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 160);
            $t->string('document_type_code', 80);
            $t->string('ownership', 16);
            $t->foreignUuid('carrier_id')->nullable()->constrained();
            $t->foreignUuid('broker_tenant_id')->nullable()->constrained('tenants');
            $t->foreignUuid('product_id')->nullable()->constrained('insurance_products');
            $t->string('insurance_class', 40)->nullable();
            $t->string('language', 12);
            $t->unsignedInteger('version');
            $t->string('status', 16)->default('DRAFT');
            $t->string('title_en', 200);
            $t->string('title_fr', 200);
            $t->jsonb('content');
            $t->string('content_hash', 64);
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('submitted_by')->nullable()->constrained('users');
            $t->timestampTz('submitted_at')->nullable();
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->foreignUuid('published_by')->nullable()->constrained('users');
            $t->timestampTz('published_at')->nullable();
            $t->timestampTz('retired_at')->nullable();
            $t->string('retire_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['code', 'version']);
            $t->index(['document_type_code', 'status']);
        });
        DB::statement("ALTER TABLE document_templates ADD CONSTRAINT document_template_ownership CHECK (ownership IN ('PLATFORM','INSURER','BROKER','REGULATORY'))");
        DB::statement("ALTER TABLE document_templates ADD CONSTRAINT document_template_status CHECK (status IN ('DRAFT','REVIEW','APPROVED','PUBLISHED','RETIRED'))");
        DB::statement("ALTER TABLE document_templates ADD CONSTRAINT document_template_language CHECK (language IN ('FR','EN','BILINGUAL'))");
        DB::statement('ALTER TABLE document_templates ADD CONSTRAINT document_template_checker CHECK (approved_by IS NULL OR approved_by <> created_by)');

        Schema::create('document_issuance_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_id')->constrained();
            $t->foreignUuid('product_id')->nullable()->constrained('insurance_products');
            $t->string('issuance_mode', 24)->default('MANUAL_UPLOAD');
            $t->boolean('opes_rendering_authorized')->default(false);
            $t->string('authorization_reference', 120)->nullable();
            $t->foreignUuid('authorized_by')->nullable()->constrained('users');
            $t->timestampTz('authorized_at')->nullable();
            $t->jsonb('languages')->default('["FR","EN"]');
            $t->string('default_language', 12)->default('BILINGUAL');
            $t->string('signature_mode', 24)->default('NONE');
            $t->string('signatory_name', 160)->nullable();
            $t->string('signatory_title', 160)->nullable();
            $t->boolean('qr_enabled')->default(true);
            $t->string('qr_payload', 24)->default('VERIFY_URL');
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX document_issuance_profile_scope ON document_issuance_profiles (carrier_id, COALESCE(product_id, \'00000000-0000-0000-0000-000000000000\'::uuid))');
        DB::statement("ALTER TABLE document_issuance_profiles ADD CONSTRAINT document_issuance_mode CHECK (issuance_mode IN ('MANUAL_UPLOAD','OPES_GENERATED','INSURER_API','HYBRID'))");

        Schema::create('document_numbering_families', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->string('family_code', 24);
            $t->string('prefix', 24);
            $t->boolean('include_year')->default(true);
            $t->unsignedSmallInteger('pad')->default(6);
            $t->jsonb('document_type_codes')->default('[]');
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX document_numbering_family_scope ON document_numbering_families (COALESCE(tenant_id, \'00000000-0000-0000-0000-000000000000\'::uuid), family_code)');

        Schema::create('document_number_counters', function (Blueprint $t) {
            $t->string('scope_key', 64);
            $t->string('family_code', 24);
            $t->string('period', 8);
            $t->unsignedBigInteger('last_value')->default(0);
            $t->timestampsTz();
            $t->primary(['scope_key', 'family_code', 'period']);
        });

        Schema::create('document_pack_manifests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained();
            $t->string('pack_code', 80);
            $t->string('trigger', 40);
            $t->string('event_reference', 80);
            $t->string('event_label', 80);
            $t->unsignedInteger('sequence')->default(0);
            $t->unsignedInteger('policy_version')->default(1);
            $t->jsonb('items');
            $t->timestampTz('generated_at');
            $t->timestampsTz();
            $t->unique(['policy_id', 'trigger', 'event_reference']);
        });

        Schema::table('documents', function (Blueprint $t) {
            $t->string('document_type_code', 80)->nullable();
            $t->string('document_type_id', 60)->nullable();
            $t->string('document_group', 40)->nullable();
            $t->string('pack_code', 80)->nullable();
            $t->foreignUuid('pack_manifest_id')->nullable()->constrained('document_pack_manifests')->nullOnDelete();
            $t->foreignUuid('document_template_id')->nullable()->constrained('document_templates');
            $t->unsignedInteger('template_version')->nullable();
            $t->string('template_hash', 64)->nullable();
            $t->foreignUuid('product_id')->nullable()->constrained('insurance_products');
            $t->unsignedInteger('product_version')->nullable();
            $t->unsignedInteger('policy_version')->nullable();
            $t->foreignUuid('policy_transaction_id')->nullable()->constrained('policy_transactions');
            $t->foreignUuid('renewal_of_policy_id')->nullable()->constrained('policies');
            $t->foreignUuid('claim_id')->nullable()->constrained('claims');
            $t->string('subject_type', 16)->nullable();
            $t->string('subject_key', 120)->nullable();
            $t->string('subject_label', 160)->nullable();
            $t->string('title', 200)->nullable();
            $t->string('issuer_type', 16)->nullable();
            $t->foreignUuid('issuer_carrier_id')->nullable()->constrained('carriers');
            $t->foreignUuid('issuer_tenant_id')->nullable()->constrained('tenants');
            $t->string('language', 12)->nullable();
            $t->string('document_origin', 16)->default('CUSTOMER');
            $t->string('document_stage', 24)->nullable();
            $t->string('security_level', 24)->default('CUSTOMER_PRIVATE');
            $t->string('status', 24)->default('VALID');
            $t->text('status_reason')->nullable();
            $t->timestampTz('status_changed_at')->nullable();
            $t->foreignUuid('status_changed_by')->nullable()->constrained('users');
            $t->uuid('supersedes_document_id')->nullable();
            $t->uuid('superseded_by_document_id')->nullable();
            $t->string('numbering_family', 24)->nullable();
            $t->string('document_number', 64)->nullable();
            $t->unsignedBigInteger('document_sequence')->nullable();
            $t->string('verification_code', 24)->nullable()->unique();
            $t->string('generation_trigger', 40)->nullable();
            $t->timestampTz('issued_at')->nullable();
            $t->timestampTz('valid_from')->nullable();
            $t->timestampTz('valid_until')->nullable();
            $t->boolean('is_carrier_original')->default(false);
            $t->jsonb('provenance')->default('{}');
            $t->foreignUuid('uploaded_by')->nullable()->constrained('users');
            $t->index(['policy_id', 'status']);
            $t->index(['policy_id', 'document_type_code']);
            $t->unique(['tenant_id', 'document_number']);
        });
        Schema::table('documents', function (Blueprint $t) {
            $t->foreign('supersedes_document_id')->references('id')->on('documents');
            $t->foreign('superseded_by_document_id')->references('id')->on('documents');
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT document_lifecycle_status CHECK (status IN ('DRAFT','GENERATED','PENDING_SIGNATURE','ISSUED','VALID','SUPERSEDED','REPLACED','REVOKED','EXPIRED','CANCELLED'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT document_origin_allowed CHECK (document_origin IN ('INSURER','BROKER','CUSTOMER','PROVIDER','GARAGE','ADJUSTER','SURVEYOR','AUTHORITY','BANK','REGULATOR','REINSURER','SYSTEM'))");

        // Existing issuance PDFs (PolicyDocumentService) are platform-rendered proof of cover.
        DB::statement("UPDATE documents SET document_origin = 'SYSTEM', issuer_type = 'PLATFORM', document_stage = 'POLICY', status = 'VALID',
            document_type_code = CASE WHEN category = 'POLICY_SCHEDULE' THEN 'POLICY_SCHEDULE' ELSE 'PROOF_OF_COVER' END, document_type_id = CASE WHEN category = 'POLICY_SCHEDULE' THEN 'DOC-022' ELSE 'DOC-028' END, document_group = 'CONTRACT',
            security_level = CASE WHEN category = 'POLICY_SCHEDULE' THEN 'CUSTOMER_PRIVATE' ELSE 'PUBLIC_VERIFIABLE' END,
            issued_at = created_at WHERE category IN ('POLICY_CERTIFICATE','POLICY_SCHEDULE')");

        Schema::create('document_status_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('document_id')->constrained('documents');
            $t->string('action', 16);
            $t->text('reason');
            $t->foreignUuid('replacement_document_id')->nullable()->constrained('documents');
            $t->string('status', 16)->default('PENDING');
            $t->foreignUuid('requested_by')->constrained('users');
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE document_status_changes ADD CONSTRAINT document_status_change_action CHECK (action IN ('REVOKE','REPLACE','CANCEL'))");
        DB::statement('ALTER TABLE document_status_changes ADD CONSTRAINT document_status_change_checker CHECK (decided_by IS NULL OR decided_by <> requested_by)');

        Schema::table('public_verification_lookups', function (Blueprint $t) {
            $t->foreignUuid('document_id')->nullable()->constrained('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('public_verification_lookups', fn (Blueprint $t) => $t->dropConstrainedForeignId('document_id'));
        Schema::dropIfExists('document_status_changes');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS document_lifecycle_status');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS document_origin_allowed');
        Schema::table('documents', function (Blueprint $t) {
            $t->dropForeign(['supersedes_document_id']);
            $t->dropForeign(['superseded_by_document_id']);
            $t->dropUnique(['tenant_id', 'document_number']);
            foreach (['pack_manifest_id', 'document_template_id', 'product_id', 'policy_transaction_id', 'renewal_of_policy_id', 'claim_id', 'issuer_carrier_id', 'issuer_tenant_id', 'status_changed_by', 'uploaded_by'] as $fk) {
                $t->dropConstrainedForeignId($fk);
            }
            $t->dropColumn(['document_type_code', 'document_type_id', 'document_group', 'pack_code', 'template_version', 'template_hash', 'product_version', 'policy_version', 'subject_type', 'subject_key', 'subject_label', 'title', 'issuer_type', 'language', 'document_origin', 'document_stage', 'security_level', 'status', 'status_reason', 'status_changed_at', 'supersedes_document_id', 'superseded_by_document_id', 'numbering_family', 'document_number', 'document_sequence', 'verification_code', 'generation_trigger', 'issued_at', 'valid_from', 'valid_until', 'is_carrier_original', 'provenance']);
        });
        foreach (['document_pack_manifests', 'document_number_counters', 'document_numbering_families', 'document_issuance_profiles', 'document_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
