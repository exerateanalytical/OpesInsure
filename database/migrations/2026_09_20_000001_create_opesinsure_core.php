<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('type', 24); $t->string('legal_name'); $t->string('trade_name')->nullable();
            $t->string('status', 24)->default('PENDING'); $t->string('country_code', 2)->default('CM'); $t->string('currency', 3)->default('XAF');
            $t->jsonb('settings')->default('{}'); $t->timestampsTz(); $t->softDeletesTz();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('full_name'); $t->string('email')->nullable()->unique(); $t->string('phone_e164')->unique();
            $t->string('password')->nullable(); $t->string('locale', 5)->default('en'); $t->string('status', 24)->default('ACTIVE');
            $t->timestampTz('email_verified_at')->nullable(); $t->timestampTz('phone_verified_at')->nullable(); $t->rememberToken(); $t->timestampsTz(); $t->softDeletesTz();
        });
        Schema::create('tenant_memberships', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('role_code', 64); $t->string('status', 24)->default('ACTIVE'); $t->timestampsTz(); $t->unique(['tenant_id', 'user_id', 'role_code']);
        });
        Schema::create('parties', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('type', 20); $t->string('display_name'); $t->jsonb('legal_identity')->default('{}');
            $t->string('status', 24)->default('ACTIVE'); $t->timestampsTz(); $t->softDeletesTz();
        });
        Schema::create('party_contacts', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('party_id')->constrained()->cascadeOnDelete(); $t->string('type', 16); $t->string('normalized_value');
            $t->boolean('is_primary')->default(false); $t->timestampTz('verified_at')->nullable(); $t->timestampsTz(); $t->unique(['type', 'normalized_value']);
        });
        Schema::create('tenant_customers', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained(); $t->foreignUuid('party_id')->constrained();
            $t->string('customer_number', 64); $t->string('status', 24)->default('ACTIVE'); $t->jsonb('private_metadata')->default('{}'); $t->timestampsTz();
            $t->unique(['tenant_id', 'party_id']); $t->unique(['tenant_id', 'customer_number']);
        });
        Schema::create('partners', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->nullable()->constrained(); $t->foreignUuid('party_id')->constrained();
            $t->string('type', 24); $t->string('licence_number')->nullable(); $t->date('licence_expires_on')->nullable(); $t->string('status', 24)->default('PENDING');
            $t->jsonb('compliance')->default('{}'); $t->timestampsTz();
        });
        Schema::create('customer_attributions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('party_id')->constrained(); $t->foreignUuid('partner_id')->nullable()->constrained();
            $t->string('origin_type', 24); $t->string('terms_version', 32); $t->timestampTz('effective_from'); $t->timestampTz('effective_until')->nullable();
            $t->string('status', 24)->default('ACTIVE'); $t->string('evidence_reference')->nullable(); $t->foreignUuid('recorded_by')->constrained('users'); $t->timestampsTz();
        });
        Schema::create('carriers', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('party_id')->constrained(); $t->string('cima_code')->unique(); $t->string('status', 24)->default('PENDING');
            $t->jsonb('capabilities')->default('{}'); $t->timestampsTz();
        });
        Schema::create('insurance_products', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('carrier_id')->constrained(); $t->string('line_code', 32); $t->string('code', 64); $t->string('name');
            $t->unsignedInteger('version'); $t->date('effective_from'); $t->date('effective_until')->nullable(); $t->string('status', 24)->default('DRAFT');
            $t->jsonb('coverages')->default('[]'); $t->jsonb('eligibility_rules')->default('{}'); $t->timestampsTz(); $t->unique(['carrier_id', 'code', 'version']);
        });
        Schema::create('tariff_versions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('insurance_product_id')->constrained(); $t->unsignedInteger('version'); $t->date('effective_from'); $t->date('effective_until')->nullable();
            $t->string('status', 24)->default('DRAFT'); $t->jsonb('input_schema'); $t->jsonb('rules'); $t->string('rules_hash', 64); $t->timestampsTz();
            $t->unique(['insurance_product_id', 'version']);
        });
        Schema::create('quotes', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained(); $t->foreignUuid('party_id')->constrained(); $t->foreignUuid('attribution_id')->nullable()->constrained('customer_attributions');
            $t->string('line_code', 32); $t->string('status', 24); $t->string('currency', 3)->default('XAF'); $t->jsonb('risk_facts');
            $t->timestampTz('expires_at')->nullable(); $t->unsignedInteger('version')->default(1); $t->timestampsTz();
        });
        Schema::create('quote_offers', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('quote_id')->constrained()->cascadeOnDelete(); $t->foreignUuid('carrier_id')->constrained();
            $t->foreignUuid('product_id')->constrained('insurance_products'); $t->foreignUuid('tariff_version_id')->constrained(); $t->bigInteger('premium_minor');
            $t->bigInteger('tax_minor')->default(0); $t->bigInteger('fee_minor')->default(0); $t->bigInteger('total_minor'); $t->string('currency', 3);
            $t->string('status', 24); $t->jsonb('calculation_breakdown'); $t->timestampTz('valid_until'); $t->timestampsTz();
        });
        Schema::create('proposals', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained(); $t->foreignUuid('quote_offer_id')->constrained(); $t->foreignUuid('party_id')->constrained();
            $t->string('status', 32); $t->jsonb('disclosures')->default('{}'); $t->timestampTz('submitted_at')->nullable(); $t->unsignedInteger('version')->default(1); $t->timestampsTz();
        });
        Schema::create('payment_intents', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained(); $t->foreignUuid('proposal_id')->constrained();
            $t->string('provider', 32); $t->string('provider_reference')->nullable(); $t->string('payer_phone_e164'); $t->bigInteger('amount_minor'); $t->string('currency', 3);
            $t->string('status', 32); $t->string('idempotency_key', 128); $t->jsonb('provider_snapshot')->default('{}'); $t->timestampsTz();
            $t->unique(['tenant_id', 'idempotency_key']); $t->unique(['provider', 'provider_reference']);
        });
        Schema::create('policies', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained(); $t->foreignUuid('proposal_id')->constrained(); $t->foreignUuid('carrier_id')->constrained();
            $t->foreignUuid('party_id')->constrained(); $t->string('policy_number')->nullable(); $t->string('certificate_number')->nullable(); $t->string('status', 32);
            $t->dateTimeTz('coverage_starts_at'); $t->dateTimeTz('coverage_ends_at'); $t->jsonb('terms_snapshot'); $t->unsignedInteger('version')->default(1); $t->timestampsTz();
            $t->unique(['carrier_id', 'policy_number']); $t->unique(['carrier_id', 'certificate_number']);
        });
        Schema::create('ledger_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->nullable()->constrained(); $t->string('code', 64); $t->string('name'); $t->string('type', 24); $t->string('currency', 3);
            $t->string('status', 24)->default('ACTIVE'); $t->timestampsTz(); $t->unique(['tenant_id', 'code', 'currency']);
        });
        Schema::create('journals', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->nullable()->constrained(); $t->string('reference_type', 64); $t->uuid('reference_id');
            $t->string('currency', 3); $t->string('status', 16)->default('POSTED'); $t->foreignUuid('reverses_journal_id')->nullable();
            $t->string('correlation_id', 64); $t->timestampTz('posted_at'); $t->timestampsTz();
        });
        Schema::table('journals', function (Blueprint $t) {
            $t->foreign('reverses_journal_id')->references('id')->on('journals');
        });
        Schema::create('journal_lines', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('journal_id')->constrained()->cascadeOnDelete(); $t->foreignUuid('account_id')->constrained('ledger_accounts');
            $t->bigInteger('debit_minor')->default(0); $t->bigInteger('credit_minor')->default(0); $t->jsonb('dimensions')->default('{}'); $t->timestampsTz();
        });
        Schema::create('commission_accruals', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('policy_id')->constrained(); $t->foreignUuid('partner_id')->nullable()->constrained(); $t->string('rule_version', 32);
            $t->bigInteger('amount_minor'); $t->string('currency', 3); $t->string('status', 24); $t->timestampTz('vests_at')->nullable(); $t->timestampsTz();
        });
        Schema::create('settlement_batches', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('carrier_id')->constrained(); $t->string('period_start'); $t->string('period_end'); $t->bigInteger('net_amount_minor');
            $t->string('currency', 3); $t->string('status', 24); $t->foreignUuid('prepared_by')->constrained('users'); $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable(); $t->timestampsTz();
        });
        Schema::create('claims', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained(); $t->foreignUuid('policy_id')->constrained(); $t->string('claim_number', 64)->unique();
            $t->string('status', 32); $t->timestampTz('loss_occurred_at'); $t->jsonb('loss_details'); $t->timestampTz('submitted_at')->nullable(); $t->timestampsTz();
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->nullable()->constrained(); $t->foreignUuid('party_id')->nullable()->constrained();
            $t->string('category', 48); $t->string('storage_key'); $t->string('mime_type', 128); $t->bigInteger('size_bytes'); $t->string('sha256', 64);
            $t->string('scan_status', 24)->default('PENDING'); $t->string('verification_status', 24)->default('UNVERIFIED'); $t->jsonb('ocr_data')->default('{}'); $t->timestampsTz();
        });
        Schema::create('fulfilment_orders', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained(); $t->foreignUuid('policy_id')->constrained(); $t->string('status', 32);
            $t->jsonb('delivery_address'); $t->timestampTz('sla_due_at')->nullable(); $t->string('delivery_otp_hash')->nullable(); $t->jsonb('proof_of_delivery')->default('{}'); $t->timestampsTz();
        });
        Schema::create('webhook_inbox', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('provider', 32); $t->string('external_event_id'); $t->string('signature_hash', 64); $t->jsonb('payload');
            $t->timestampTz('received_at'); $t->timestampTz('processed_at')->nullable(); $t->string('status', 24)->default('RECEIVED'); $t->text('failure_reason')->nullable();
            $t->unique(['provider', 'external_event_id']);
        });
        Schema::create('outbox_messages', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('event_name'); $t->unsignedSmallInteger('event_version')->default(1); $t->string('aggregate_type'); $t->uuid('aggregate_id');
            $t->jsonb('payload'); $t->jsonb('metadata')->default('{}'); $t->timestampTz('occurred_at'); $t->timestampTz('published_at')->nullable(); $t->unsignedInteger('attempts')->default(0);
        });
        Schema::create('inbox_messages', function (Blueprint $t) {
            $t->uuid('message_id'); $t->string('consumer'); $t->timestampTz('processed_at'); $t->primary(['message_id', 'consumer']);
        });
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->nullable()->constrained(); $t->foreignUuid('user_id')->nullable()->constrained(); $t->string('key', 128);
            $t->string('operation', 128); $t->string('request_hash', 64); $t->unsignedSmallInteger('response_status')->nullable(); $t->jsonb('response_body')->nullable(); $t->timestampTz('expires_at');
            $t->unique(['tenant_id', 'user_id', 'key', 'operation']);
        });
        Schema::create('audit_log', function (Blueprint $t) {
            $t->bigIncrements('sequence'); $t->uuid('id')->unique(); $t->foreignUuid('tenant_id')->nullable()->constrained(); $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->string('action', 128); $t->string('subject_type', 128); $t->uuid('subject_id')->nullable(); $t->string('reason_code', 64)->nullable();
            $t->jsonb('metadata')->default('{}'); $t->string('correlation_id', 64); $t->string('previous_hash', 64)->nullable(); $t->string('entry_hash', 64); $t->timestampTz('created_at');
        });
        DB::statement("CREATE INDEX quotes_risk_facts_gin ON quotes USING GIN (risk_facts)");
        DB::statement("CREATE INDEX outbox_unpublished_idx ON outbox_messages (occurred_at) WHERE published_at IS NULL");
        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side CHECK ((debit_minor = 0 AND credit_minor > 0) OR (credit_minor = 0 AND debit_minor > 0))");
    }

    public function down(): void
    {
        foreach (['audit_log','idempotency_keys','inbox_messages','outbox_messages','webhook_inbox','fulfilment_orders','documents','claims','settlement_batches','commission_accruals','journal_lines','journals','ledger_accounts','policies','payment_intents','proposals','quote_offers','quotes','tariff_versions','insurance_products','carriers','customer_attributions','partners','tenant_customers','party_contacts','parties','tenant_memberships','users','tenants'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
