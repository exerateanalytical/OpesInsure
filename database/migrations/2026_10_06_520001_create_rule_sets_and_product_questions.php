<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Batch 5B — REQ-RUL-001…004, REQ-DUP-020.
 *
 *  rule_sets / rules          versioned, effective-dated structured rules (ELIGIBILITY, COMPLETENESS, …) per
 *                             product version (insurance_products row), insurance line or platform; maker-checker
 *                             through approval_requests (rule_set.approve). Thresholds live here, never in code.
 *  question_sets /            THE risk-question source (REQ-DUP-020): one versioned question set per product version
 *  product_questions          (or a line default), 12 PRE §17 question types, EN/FR, validation, visibility, risk
 *                             factor. RiskSchemaCatalogue / NonMotorRiskSchemas / MotorRiskSchema are seed data,
 *                             synced here by QuestionSetCatalogue (this migration runs the first sync).
 *
 * Additive only. insurance_products.eligibility_rules and disclosure_schema_versions stay readable (legacy adapters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_sets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('code', 96);
            $t->string('domain', 32);
            $t->string('scope_type', 24); // PRODUCT_VERSION | LINE | PLATFORM
            $t->foreignUuid('insurance_product_id')->nullable()->constrained('insurance_products');
            $t->string('line_code', 32)->nullable();
            $t->string('operation', 24)->nullable(); // COMPLETENESS: QUOTE | BIND | ISSUE | CLAIM
            $t->unsignedInteger('version');
            $t->string('status', 24)->default('DRAFT');
            $t->char('content_hash', 64)->nullable();
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->text('description')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('submitted_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('retired_at')->nullable();
            $t->uuid('approval_request_id')->nullable();
            $t->timestampsTz();
            $t->unique(['code', 'version']);
            $t->index(['domain', 'scope_type', 'status']);
        });

        Schema::create('rules', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('rule_set_id')->constrained('rule_sets')->cascadeOnDelete();
            $t->string('code', 96);
            $t->string('name_en', 191);
            $t->string('name_fr', 191)->nullable();
            $t->integer('priority')->default(100);
            $t->boolean('stop_processing')->default(false);
            $t->jsonb('condition');
            $t->jsonb('outcome');
            $t->text('explanation_en')->nullable();
            $t->text('explanation_fr')->nullable();
            $t->boolean('enabled')->default(true);
            $t->timestampsTz();
            $t->unique(['rule_set_id', 'code']);
        });

        Schema::create('question_sets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('scope_type', 24); // PRODUCT_VERSION | LINE
            $t->foreignUuid('insurance_product_id')->nullable()->constrained('insurance_products');
            $t->string('line_code', 32);
            $t->string('stage', 16)->default('QUOTE'); // QUOTE | PROPOSAL
            $t->unsignedInteger('version');
            $t->string('status', 24)->default('DRAFT');
            $t->string('source', 24)->default('MANUAL'); // SEED_CATALOGUE | MANUAL
            $t->unsignedInteger('schema_version')->default(1); // the wizard `version` served to the app
            $t->jsonb('presentation')->default('{}'); // steps, required (rating keys) and other schema-level keys
            $t->char('schema_hash', 64);
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('submitted_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->uuid('approval_request_id')->nullable();
            $t->timestampsTz();
            $t->index(['line_code', 'stage', 'status']);
        });

        Schema::create('product_questions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('question_set_id')->constrained('question_sets')->cascadeOnDelete();
            $t->string('code', 96);
            $t->string('question_type', 24);
            $t->string('input_type', 32); // wizard renderer type (select_master, vehicle_make, …) — InputFieldContract input
            $t->string('label_en', 255);
            $t->string('label_fr', 255)->nullable();
            $t->string('step_code', 64)->nullable();
            $t->unsignedInteger('display_order');
            $t->boolean('required')->default(false);
            $t->jsonb('options')->nullable();
            $t->jsonb('validation')->default('{}');
            $t->jsonb('visibility')->nullable(); // structured expression (REQ-RUL-002 grammar)
            $t->string('fact_key', 96);
            $t->boolean('risk_factor')->default(false);
            $t->jsonb('rendered_field'); // the full rendered field (source, item_fields, allocation, …) — the mobile contract
            $t->timestampsTz();
            $t->unique(['question_set_id', 'code']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE rule_sets ADD CONSTRAINT rule_sets_status_allowed CHECK (status IN ('DRAFT','IN_REVIEW','APPROVED','REJECTED','RETIRED'))");
            DB::statement("ALTER TABLE rule_sets ADD CONSTRAINT rule_sets_domain_allowed CHECK (domain IN ('ELIGIBILITY','COMPLETENESS','UNDERWRITING','REFERRAL','DOCUMENTS','QUESTION_EFFECT'))");
            DB::statement("ALTER TABLE rule_sets ADD CONSTRAINT rule_sets_scope_shape CHECK ((scope_type = 'PRODUCT_VERSION' AND insurance_product_id IS NOT NULL) OR (scope_type = 'LINE' AND line_code IS NOT NULL AND insurance_product_id IS NULL) OR (scope_type = 'PLATFORM' AND insurance_product_id IS NULL AND line_code IS NULL))");
            DB::statement("ALTER TABLE rule_sets ADD CONSTRAINT rule_sets_operation_allowed CHECK (operation IS NULL OR operation IN ('QUOTE','BIND','ISSUE','CLAIM'))");
            DB::statement('ALTER TABLE rule_sets ADD CONSTRAINT rule_sets_maker_checker CHECK (approved_by IS NULL OR created_by IS NULL OR approved_by <> created_by)');
            DB::statement('ALTER TABLE rule_sets ADD CONSTRAINT rule_sets_effective_range CHECK (effective_until IS NULL OR effective_until >= effective_from)');
            DB::statement("ALTER TABLE question_sets ADD CONSTRAINT question_sets_status_allowed CHECK (status IN ('DRAFT','IN_REVIEW','APPROVED','REJECTED','RETIRED'))");
            DB::statement("ALTER TABLE question_sets ADD CONSTRAINT question_sets_stage_allowed CHECK (stage IN ('QUOTE','PROPOSAL'))");
            DB::statement("ALTER TABLE question_sets ADD CONSTRAINT question_sets_scope_shape CHECK ((scope_type = 'PRODUCT_VERSION' AND insurance_product_id IS NOT NULL) OR (scope_type = 'LINE' AND insurance_product_id IS NULL))");
            DB::statement('ALTER TABLE question_sets ADD CONSTRAINT question_sets_maker_checker CHECK (approved_by IS NULL OR created_by IS NULL OR approved_by <> created_by)');
            DB::statement("ALTER TABLE product_questions ADD CONSTRAINT product_questions_type_allowed CHECK (question_type IN ('TEXT','NUMBER','DATE','SELECT','MULTI_SELECT','BOOLEAN','CURRENCY','PERCENTAGE','ADDRESS','DOCUMENT','PHOTO','ENTITY_REFERENCE','REPEATING_GROUP'))");
            DB::statement('CREATE UNIQUE INDEX question_sets_line_version ON question_sets (line_code, stage, version) WHERE insurance_product_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX question_sets_product_version ON question_sets (insurance_product_id, stage, version) WHERE insurance_product_id IS NOT NULL');
        }

        // Approval matrix default for the new catalogued actions (the defaults migration already ran in production).
        $now = now();
        foreach (['rule_set.approve', 'question_set.approve'] as $code) {
            $a = \App\Application\Approvals\ApprovalActionCatalogue::ACTIONS[$code] ?? null;
            if ($a && Schema::hasTable('approval_matrix_rules') && ! DB::table('approval_matrix_rules')->whereNull('tenant_id')->where('action_code', $code)->exists()) {
                DB::table('approval_matrix_rules')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => null, 'action_code' => $code, 'workflow' => $a['workflow'], 'category' => $a['category'],
                    'description' => $a['description'], 'source_refs' => $a['sources'], 'checker_permission' => $a['checker_permission'] ?? null,
                    'required_approvals' => 1, 'requires_maker_checker' => true, 'exclude_subject_parties' => true, 'priority' => 1000,
                    'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // REQ-DUP-020: the PHP wizard schemas become seed data — first sync into question_sets/product_questions.
        app(\App\Application\Rules\QuestionSetCatalogue::class)->syncSeedCatalogue();
    }

    public function down(): void
    {
        DB::table('approval_matrix_rules')->whereNull('tenant_id')->whereIn('action_code', ['rule_set.approve', 'question_set.approve'])->delete();
        Schema::dropIfExists('product_questions');
        Schema::dropIfExists('question_sets');
        Schema::dropIfExists('rules');
        Schema::dropIfExists('rule_sets');
    }
};
