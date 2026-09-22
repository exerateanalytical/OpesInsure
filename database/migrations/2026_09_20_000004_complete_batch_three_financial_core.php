<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('policy_transactions', function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('tenant_id')->constrained();$t->foreignUuid('policy_id')->constrained()->cascadeOnDelete();$t->string('type',32);$t->string('status',32)->default('DRAFT');
            $t->string('transaction_number',64)->unique();$t->dateTimeTz('effective_at');$t->jsonb('requested_changes')->default('{}');$t->jsonb('terms_before')->default('{}');$t->jsonb('terms_after')->default('{}');
            $t->bigInteger('premium_delta_minor')->default(0);$t->string('currency',3)->default('XAF');$t->string('reason_code',64);$t->text('notes')->nullable();$t->foreignUuid('requested_by')->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');$t->timestampTz('approved_at')->nullable();$t->timestampsTz();
        });
        Schema::create('policy_status_history',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('policy_id')->constrained()->cascadeOnDelete();$t->string('from_status',32)->nullable();$t->string('to_status',32);$t->string('reason_code',64);
            $t->foreignUuid('actor_id')->nullable()->constrained('users');$t->jsonb('metadata')->default('{}');$t->timestampTz('occurred_at');
        });
        Schema::create('payment_events',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('payment_intent_id')->constrained()->cascadeOnDelete();$t->string('type',40);$t->string('provider_event_id')->nullable();$t->string('previous_status',32)->nullable();
            $t->string('new_status',32);$t->bigInteger('amount_minor');$t->string('currency',3);$t->jsonb('provider_payload')->default('{}');$t->timestampTz('occurred_at');$t->unique(['payment_intent_id','provider_event_id']);
        });
        Schema::create('refunds',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('tenant_id')->constrained();$t->foreignUuid('payment_intent_id')->constrained();$t->string('refund_number',64)->unique();$t->bigInteger('amount_minor');$t->string('currency',3);
            $t->string('status',32)->default('REQUESTED');$t->string('reason_code',64);$t->text('notes')->nullable();$t->string('provider_reference')->nullable();$t->foreignUuid('requested_by')->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');$t->timestampTz('approved_at')->nullable();$t->timestampTz('completed_at')->nullable();$t->timestampsTz();
        });
        Schema::create('chargebacks',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('tenant_id')->constrained();$t->foreignUuid('payment_intent_id')->constrained();$t->string('provider_case_reference')->unique();$t->bigInteger('amount_minor');$t->string('currency',3);
            $t->string('status',32)->default('OPEN');$t->string('reason_code',64);$t->timestampTz('response_due_at')->nullable();$t->jsonb('evidence')->default('[]');$t->timestampTz('resolved_at')->nullable();$t->timestampsTz();
        });
        Schema::create('commission_rule_versions',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('carrier_id')->constrained();$t->foreignUuid('product_id')->nullable()->constrained('insurance_products');$t->foreignUuid('partner_id')->nullable()->constrained();
            $t->unsignedInteger('version');$t->date('effective_from');$t->date('effective_until')->nullable();$t->string('status',24)->default('DRAFT');$t->unsignedInteger('basis_points');$t->unsignedInteger('vesting_days')->default(7);
            $t->unsignedInteger('holdback_basis_points')->default(0);$t->jsonb('conditions')->default('{}');$t->string('rule_hash',64);$t->timestampsTz();$t->unique(['carrier_id','product_id','partner_id','version']);
        });
        Schema::create('commission_movements',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('commission_accrual_id')->constrained()->cascadeOnDelete();$t->string('type',32);$t->bigInteger('amount_minor');$t->string('currency',3);$t->string('reason_code',64);
            $t->foreignUuid('journal_id')->nullable()->constrained('journals');$t->foreignUuid('actor_id')->nullable()->constrained('users');$t->timestampTz('occurred_at');$t->jsonb('metadata')->default('{}');
        });
        Schema::create('partner_payout_requests',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('partner_id')->constrained();$t->string('payout_number',64)->unique();$t->bigInteger('amount_minor');$t->string('currency',3);$t->string('status',32)->default('REQUESTED');
            $t->string('destination_type',24);$t->text('destination_encrypted');$t->foreignUuid('requested_by')->constrained('users');$t->foreignUuid('approved_by')->nullable()->constrained('users');$t->timestampTz('approved_at')->nullable();$t->timestampTz('paid_at')->nullable();$t->timestampsTz();
        });
        Schema::table('policies',function(Blueprint $t){$t->foreignUuid('payment_intent_id')->nullable()->constrained();$t->string('currency',3)->default('XAF');$t->bigInteger('premium_minor')->default(0);$t->timestampTz('issued_at')->nullable();$t->string('issuance_reference')->nullable();});
        Schema::table('commission_accruals',function(Blueprint $t){$t->foreignUuid('rule_version_id')->nullable()->constrained('commission_rule_versions');$t->bigInteger('vested_minor')->default(0);$t->bigInteger('paid_minor')->default(0);$t->bigInteger('clawed_back_minor')->default(0);});
        DB::statement("ALTER TABLE journals ADD CONSTRAINT journals_status_allowed CHECK (status IN ('DRAFT','POSTED','REVERSED'))");
        DB::statement("ALTER TABLE commission_rule_versions ADD CONSTRAINT commission_rates_valid CHECK (basis_points <= 10000 AND holdback_basis_points <= 10000)");
    }
    public function down(): void
    {
        Schema::table('commission_accruals',function(Blueprint $t){$t->dropConstrainedForeignId('rule_version_id');$t->dropColumn(['vested_minor','paid_minor','clawed_back_minor']);});
        Schema::table('policies',function(Blueprint $t){$t->dropConstrainedForeignId('payment_intent_id');$t->dropColumn(['currency','premium_minor','issued_at','issuance_reference']);});
        foreach(['partner_payout_requests','commission_movements','commission_rule_versions','chargebacks','refunds','payment_events','policy_status_history','policy_transactions'] as $table)Schema::dropIfExists($table);
    }
};
