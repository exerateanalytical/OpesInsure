<?php

declare(strict_types=1);

use App\Application\Cases\CaseTypeCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | Batch 8-9 — REQ-DOC-008/009/010/012. Additive only.
 |
 |  REQ-DOC-008: documents.document_origin / document_stage already exist (2026_09_29_110001). Third-party
 |    evidence stays in the one canonical `documents` store (REQ-DUP-021) and gets its own read surface:
 |    view `third_party_evidence_documents` (origin outside INSURER/BROKER/SYSTEM).
 |  REQ-DOC-009: retention_schedules (maker-checker, periods are owner input — none seeded, ICE gap 20),
 |    legal_holds on ANY subject (document_retention_holds kept as legacy document-only holds and still
 |    honoured), document_destruction_requests driven through a DOCUMENT_DESTRUCTION case.
 |  REQ-DOC-010: document_intake_items (classification against the 220-document register).
 |  REQ-DOC-012: signature_requests + signature_request_signers (provider-agnostic; MANUAL click-to-sign).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->timestampTz('destroyed_at')->nullable();
            $t->foreignUuid('destroyed_by')->nullable()->constrained('users');
        });

        DB::statement("CREATE OR REPLACE VIEW third_party_evidence_documents AS
            SELECT d.id, d.tenant_id, d.party_id, d.policy_id, d.claim_id, d.document_type_code, d.document_type_id, d.document_origin, d.document_stage, d.security_level, d.sha256, d.created_at FROM documents d WHERE d.document_origin NOT IN ('INSURER','BROKER','SYSTEM')");

        Schema::create('retention_schedules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->string('code', 64);
            $t->string('document_type_code', 96)->nullable();
            $t->string('document_group', 40)->nullable();
            $t->string('security_level', 32)->nullable();
            $t->unsignedSmallInteger('retention_years');
            $t->string('trigger_event', 24)->default('ISSUED_AT');
            $t->string('disposition', 16)->default('DESTROY');
            $t->text('legal_basis')->nullable();
            $t->string('status', 16)->default('DRAFT');
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE retention_schedules ADD CONSTRAINT retention_schedules_status CHECK (status IN ('DRAFT','ACTIVE','RETIRED'))");
        DB::statement("ALTER TABLE retention_schedules ADD CONSTRAINT retention_schedules_trigger CHECK (trigger_event IN ('ISSUED_AT','CREATED_AT','VALID_UNTIL'))");
        DB::statement("ALTER TABLE retention_schedules ADD CONSTRAINT retention_schedules_disposition CHECK (disposition IN ('DESTROY','REVIEW'))");
        DB::statement('ALTER TABLE retention_schedules ADD CONSTRAINT retention_schedules_checker CHECK (approved_by IS NULL OR approved_by <> created_by)');

        Schema::create('legal_holds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('subject_type', 32);
            $t->uuid('subject_id');
            $t->string('reason_code', 64);
            $t->text('notes');
            $t->uuid('case_id')->nullable();
            $t->date('hold_until')->nullable();
            $t->foreignUuid('placed_by')->constrained('users');
            $t->timestampTz('placed_at');
            $t->timestampTz('released_at')->nullable();
            $t->foreignUuid('released_by')->nullable()->constrained('users');
            $t->text('release_reason')->nullable();
            $t->timestampsTz();
            $t->index(['subject_type', 'subject_id']);
        });

        Schema::create('document_destruction_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('document_id')->constrained('documents');
            $t->uuid('case_id')->nullable();
            $t->foreignUuid('retention_schedule_id')->nullable()->constrained('retention_schedules');
            $t->string('status', 16)->default('PENDING');
            $t->text('reason');
            $t->foreignUuid('requested_by')->constrained('users');
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestampTz('executed_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE document_destruction_requests ADD CONSTRAINT document_destruction_status CHECK (status IN ('PENDING','APPROVED','REJECTED','DESTROYED','BLOCKED'))");
        DB::statement('ALTER TABLE document_destruction_requests ADD CONSTRAINT document_destruction_checker CHECK (decided_by IS NULL OR decided_by <> requested_by)');

        Schema::create('document_intake_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('document_id')->constrained('documents');
            $t->string('channel', 16);
            $t->string('original_filename', 255)->nullable();
            $t->string('declared_type_code', 96)->nullable();
            $t->string('suggested_type_code', 96)->nullable();
            $t->decimal('suggested_confidence', 4, 3)->nullable();
            $t->string('classified_type_code', 96)->nullable();
            $t->string('classified_type_id', 64)->nullable();
            $t->string('status', 16)->default('RECEIVED');
            $t->string('subject_type', 32)->nullable();
            $t->uuid('subject_id')->nullable();
            $t->uuid('exception_case_id')->nullable();
            $t->foreignUuid('received_by')->nullable()->constrained('users');
            $t->foreignUuid('classified_by')->nullable()->constrained('users');
            $t->timestampTz('received_at');
            $t->timestampTz('classified_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE document_intake_items ADD CONSTRAINT document_intake_status CHECK (status IN ('RECEIVED','CLASSIFIED','EXCEPTION','REJECTED'))");
        DB::statement("ALTER TABLE document_intake_items ADD CONSTRAINT document_intake_channel CHECK (channel IN ('UPLOAD','EMAIL','POST','PORTAL','API','SCAN'))");

        Schema::create('signature_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('document_id')->constrained('documents');
            $t->string('provider', 32)->default('MANUAL');
            $t->string('provider_reference', 128)->nullable();
            $t->string('status', 16)->default('PENDING');
            $t->string('document_sha256', 64);
            $t->text('consent_text');
            $t->timestampTz('expires_at')->nullable();
            $t->foreignUuid('requested_by')->constrained('users');
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE signature_requests ADD CONSTRAINT signature_requests_status CHECK (status IN ('PENDING','COMPLETED','DECLINED','EXPIRED','CANCELLED'))");

        Schema::create('signature_request_signers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('signature_request_id')->constrained('signature_requests')->cascadeOnDelete();
            $t->foreignUuid('signer_user_id')->nullable()->constrained('users');
            $t->foreignUuid('signer_party_id')->nullable()->constrained('parties');
            $t->string('signer_name', 160);
            $t->string('signer_role', 32);
            $t->unsignedSmallInteger('signing_order')->default(1);
            $t->string('status', 16)->default('PENDING');
            $t->string('method', 24)->nullable();
            $t->jsonb('evidence')->default('{}');
            $t->string('signature_hash', 64)->nullable();
            $t->timestampTz('acted_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE signature_request_signers ADD CONSTRAINT signature_signers_status CHECK (status IN ('PENDING','SIGNED','DECLINED'))");

        // REQ-DOC-009 destruction case type (case engine, maker-checker decision required before resolve).
        if (! DB::table('case_types')->where('code', 'DOCUMENT_DESTRUCTION')->exists()) {
            $def = CaseTypeCatalogue::genericLifecycle(true);
            $family = DB::table('case_families')->where('code', 'OPERATIONS')->exists() ? 'OPERATIONS' : null;
            DB::table('case_types')->insert([
                'id' => (string) Str::uuid(), 'code' => 'DOCUMENT_DESTRUCTION', 'version' => 1, 'family_code' => $family,
                'name' => 'Document destruction (retention disposal)', 'status' => 'EFFECTIVE', 'valid_from' => '2026-01-01', 'valid_to' => null,
                'states' => json_encode($def['states']), 'transitions' => json_encode($def['transitions']),
                'sla_policies' => '[]', 'auto_tasks' => '[]', 'default_confidentiality' => 'NORMAL', 'regulated' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('case_types')->where('code', 'DOCUMENT_DESTRUCTION')->whereNotExists(fn ($q) => $q->from('cases')->whereColumn('cases.case_type_id', 'case_types.id'))->delete();
        foreach (['signature_request_signers', 'signature_requests', 'document_intake_items', 'document_destruction_requests', 'legal_holds', 'retention_schedules'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP VIEW IF EXISTS third_party_evidence_documents');
        Schema::table('documents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('destroyed_by');
            $t->dropColumn('destroyed_at');
        });
    }
};
