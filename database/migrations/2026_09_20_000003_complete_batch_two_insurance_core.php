<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('insurance_lines', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('code', 32)->unique(); $t->jsonb('name'); $t->jsonb('description')->default('{}');
            $t->string('status', 24)->default('ACTIVE'); $t->jsonb('risk_schema'); $t->timestampsTz();
        });
        Schema::create('coverage_definitions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('insurance_line_id')->constrained(); $t->string('code', 64); $t->jsonb('name');
            $t->jsonb('description')->default('{}'); $t->string('limit_type', 24); $t->boolean('mandatory')->default(false); $t->timestampsTz();
            $t->unique(['insurance_line_id','code']);
        });
        Schema::create('product_coverages', function (Blueprint $t) {
            $t->foreignUuid('insurance_product_id')->constrained()->cascadeOnDelete(); $t->foreignUuid('coverage_definition_id')->constrained()->cascadeOnDelete();
            $t->bigInteger('default_limit_minor')->nullable(); $t->bigInteger('default_deductible_minor')->nullable(); $t->jsonb('configuration')->default('{}');
            $t->primary(['insurance_product_id','coverage_definition_id']);
        });
        Schema::create('fee_schedule_versions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->nullable()->constrained(); $t->string('code', 64); $t->unsignedInteger('version');
            $t->date('effective_from'); $t->date('effective_until')->nullable(); $t->string('status', 24)->default('DRAFT'); $t->jsonb('rules');
            $t->string('rules_hash',64); $t->timestampsTz(); $t->unique(['tenant_id','code','version']);
        });
        Schema::create('tax_levy_versions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('jurisdiction',8)->default('CM'); $t->string('line_code',32); $t->unsignedInteger('version');
            $t->date('effective_from'); $t->date('effective_until')->nullable(); $t->string('status',24)->default('DRAFT'); $t->jsonb('rules'); $t->string('rules_hash',64); $t->timestampsTz();
            $t->unique(['jurisdiction','line_code','version']);
        });
        Schema::create('risk_assets', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained(); $t->foreignUuid('party_id')->constrained(); $t->string('type',32);
            $t->string('external_reference',100)->nullable(); $t->string('display_name'); $t->jsonb('facts'); $t->string('facts_hash',64); $t->string('status',24)->default('ACTIVE');
            $t->unsignedInteger('version')->default(1); $t->timestampsTz(); $t->softDeletesTz(); $t->unique(['tenant_id','type','external_reference']);
        });
        Schema::create('risk_asset_documents', function (Blueprint $t) {
            $t->foreignUuid('risk_asset_id')->constrained()->cascadeOnDelete(); $t->foreignUuid('document_id')->constrained()->cascadeOnDelete(); $t->string('purpose',64);
            $t->primary(['risk_asset_id','document_id']);
        });
        Schema::create('rating_runs', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained(); $t->foreignUuid('quote_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('tariff_version_id')->constrained(); $t->foreignUuid('fee_schedule_version_id')->nullable()->constrained(); $t->foreignUuid('tax_levy_version_id')->nullable()->constrained();
            $t->string('input_hash',64); $t->jsonb('input_snapshot'); $t->jsonb('output_snapshot')->nullable(); $t->string('status',24); $t->text('failure_reason')->nullable();
            $t->timestampTz('completed_at')->nullable(); $t->timestampsTz(); $t->unique(['quote_id','tariff_version_id','input_hash']);
        });
        Schema::create('underwriting_cases', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained(); $t->foreignUuid('proposal_id')->constrained()->unique(); $t->foreignUuid('carrier_id')->constrained();
            $t->string('status',32)->default('QUEUED'); $t->string('priority',16)->default('NORMAL'); $t->jsonb('referral_reasons')->default('[]');
            $t->foreignUuid('assigned_to')->nullable()->constrained('users'); $t->timestampTz('decision_due_at')->nullable(); $t->timestampsTz();
        });
        Schema::create('underwriting_decisions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('underwriting_case_id')->constrained()->cascadeOnDelete(); $t->string('decision',24); $t->string('reason_code',64);
            $t->text('notes'); $t->jsonb('conditions')->default('[]'); $t->foreignUuid('decided_by')->constrained('users'); $t->timestampTz('decided_at'); $t->timestampsTz();
        });
        Schema::create('proposal_documents', function (Blueprint $t) {
            $t->foreignUuid('proposal_id')->constrained()->cascadeOnDelete(); $t->foreignUuid('document_id')->constrained()->cascadeOnDelete(); $t->string('requirement_code',64); $t->string('status',24)->default('SUBMITTED');
            $t->primary(['proposal_id','document_id']);
        });
        Schema::table('quotes', function (Blueprint $t) { $t->foreignUuid('risk_asset_id')->nullable()->after('party_id')->constrained('risk_assets'); $t->string('channel',24)->default('B2C'); $t->timestampTz('submitted_at')->nullable(); });
        Schema::table('quote_offers', function (Blueprint $t) { $t->string('decline_reason_code',64)->nullable(); $t->jsonb('coverage_snapshot')->default('[]'); $t->string('external_reference')->nullable(); });
        Schema::table('proposals', function (Blueprint $t) { $t->string('proposal_number',64)->nullable()->unique(); $t->jsonb('terms_snapshot')->default('{}'); $t->timestampTz('decided_at')->nullable(); });
        DB::statement("ALTER TABLE risk_assets ADD CONSTRAINT risk_assets_version_positive CHECK (version > 0)");
    }
    public function down(): void
    {
        Schema::table('proposals',fn(Blueprint $t)=>$t->dropColumn(['proposal_number','terms_snapshot','decided_at']));
        Schema::table('quote_offers',fn(Blueprint $t)=>$t->dropColumn(['decline_reason_code','coverage_snapshot','external_reference']));
        Schema::table('quotes',function(Blueprint $t){$t->dropConstrainedForeignId('risk_asset_id');$t->dropColumn(['channel','submitted_at']);});
        foreach(['proposal_documents','underwriting_decisions','underwriting_cases','rating_runs','risk_asset_documents','risk_assets','tax_levy_versions','fee_schedule_versions','product_coverages','coverage_definitions','insurance_lines'] as $table) Schema::dropIfExists($table);
    }
};
