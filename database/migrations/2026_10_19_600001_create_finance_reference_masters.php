<?php

declare(strict_types=1);

use App\Application\Finance\ReferenceMasters\FinanceReferenceCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent GP6 — Gap closure pack 06 "Banking, Payment, Accounting & GL Reference Master".
 * Adds only what the finance chain did not have (audited: ledger_accounts, accounting_event_mappings, payment_provider_connections,
 * finance_counterparty_accounts and the sub-ledger already exist and are reused, not duplicated):
 *   financial_institutions          banks + payment institutions master (platform-wide, provenance + verification status + effective dating)
 *   payment_provider_profiles       tenant payment-provider business configuration (CONFIG_REQUIRED gate; secrets never stored here)
 *   gl_control_account_mappings     control account role -> tenant ledger account code (platform baseline + versioned tenant mapping, maker-checker)
 *   cost_centres                    tenant cost-centre dimension master (CONFIG_REQUIRED until a tenant creates its own)
 * Seeding is idempotent and never overwrites admin-edited rows (FinanceReferenceCatalogue::seed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_institutions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 80)->unique();
            $t->string('institution_type', 40); // BANK | PAYMENT_INSTITUTION | MICROFINANCE
            $t->string('legal_name');
            $t->string('trade_name')->nullable();
            $t->string('bank_code', 20)->nullable();
            $t->string('bic_swift', 11)->nullable();
            $t->string('operating_status', 30)->nullable(); // null until sourced
            $t->string('head_office_city', 120)->nullable();
            $t->string('website')->nullable();
            $t->jsonb('phones')->default('[]');
            $t->jsonb('branches')->default('[]');
            $t->jsonb('aliases')->default('[]');
            $t->char('country', 2)->default('CM');
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->string('source', 80);
            $t->string('source_url')->nullable();
            $t->string('verification_status', 40);
            $t->string('dataset_version', 40)->nullable();
            $t->timestampTz('admin_edited_at')->nullable();
            $t->foreignUuid('admin_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['institution_type', 'verification_status']);
            $t->unique('bic_swift');
        });
        DB::statement("ALTER TABLE financial_institutions ADD CONSTRAINT fi_type_check CHECK (institution_type IN ('BANK','PAYMENT_INSTITUTION','MICROFINANCE'))");
        DB::statement("ALTER TABLE financial_institutions ADD CONSTRAINT fi_status_check CHECK (verification_status IN ('".implode("','", FinanceReferenceCatalogue::GAP_STATUSES)."'))");

        Schema::create('payment_provider_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('provider_type', 30);
            $t->string('adapter_provider', 32)->nullable(); // existing technical channel (config/payments.php)
            $t->foreignUuid('financial_institution_id')->nullable()->constrained('financial_institutions');
            $t->foreignUuid('connection_id')->nullable()->constrained('payment_provider_connections');
            $t->uuid('legal_entity_id')->nullable();
            $t->string('api_base_url')->nullable();
            $t->string('environment', 16)->default('SANDBOX');
            $t->string('merchant_identifier', 120)->nullable();
            $t->string('collection_account', 80)->nullable();
            $t->string('settlement_account', 80)->nullable();
            $t->jsonb('callback_profile')->default('{}');
            $t->jsonb('reconciliation_reference_rules')->default('{}');
            $t->string('settlement_cycle', 30)->nullable();
            $t->jsonb('fees')->default('[]');
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->string('status', 30)->default('CONFIG_REQUIRED');
            $t->string('source', 80)->default('TENANT_CONFIGURATION');
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'provider_type', 'status']);
        });
        DB::statement("ALTER TABLE payment_provider_profiles ADD CONSTRAINT ppp_type_check CHECK (provider_type IN ('".implode("','", FinanceReferenceCatalogue::PROVIDER_TYPES)."'))");
        DB::statement("ALTER TABLE payment_provider_profiles ADD CONSTRAINT ppp_status_check CHECK (status IN ('CONFIG_REQUIRED','PENDING_APPROVAL','ACTIVE','SUSPENDED','RETIRED'))");
        DB::statement("ALTER TABLE payment_provider_profiles ADD CONSTRAINT ppp_env_check CHECK (environment IN ('SANDBOX','PRODUCTION'))");

        Schema::create('gl_control_account_mappings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained(); // null = platform baseline
            $t->string('control_code', 40);
            $t->string('ledger_account_code', 64)->nullable(); // null = no baseline (CONFIG_REQUIRED)
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 20); // PENDING_APPROVAL | APPROVED | REJECTED | SUPERSEDED
            $t->string('data_status', 40);
            $t->string('source', 80);
            $t->string('reason', 500)->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'control_code', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX gl_cam_version_unique ON gl_control_account_mappings (COALESCE(tenant_id::text, \'\'), control_code, version)');
        DB::statement("ALTER TABLE gl_control_account_mappings ADD CONSTRAINT gl_cam_status_check CHECK (status IN ('PENDING_APPROVAL','APPROVED','REJECTED','SUPERSEDED'))");

        Schema::create('cost_centres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('name');
            $t->uuid('parent_id')->nullable();
            $t->uuid('branch_id')->nullable();
            $t->string('status', 20)->default('ACTIVE');
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });
        Schema::table('cost_centres', fn (Blueprint $t) => $t->foreign('parent_id')->references('id')->on('cost_centres'));
        DB::statement("ALTER TABLE cost_centres ADD CONSTRAINT cc_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");

        FinanceReferenceCatalogue::seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centres');
        Schema::dropIfExists('gl_control_account_mappings');
        Schema::dropIfExists('payment_provider_profiles');
        Schema::dropIfExists('financial_institutions');
    }
};
