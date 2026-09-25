<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Agent E4 — REQ-HLT-003 provider claims (cashless billing): invoice lines priced against the contracted tariff (13A),
 * line adjudication with an explanation of benefits, append-only history, settlement batches (PAYABLE obligations to the
 * provider party) and the health.provider_claim.approved / .paid accounting events.
 */
return new class extends Migration
{
    private const EVENTS = [
        'health.provider_claim.approved' => ['CLAIMS', '601000', '481000', 'Health provider claim adjudicated: insurer share recognised as claims payable.'],
        'health.provider_claim.paid' => ['CLAIMS', '481000', '521000', 'Health provider claim paid in a provider settlement batch.'],
    ];

    public function up(): void
    {
        Schema::create('health_provider_claims', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('claim_number', 64)->unique();
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->foreignUuid('provider_contract_id')->constrained('provider_contracts');
            $t->string('invoice_reference', 120);
            $t->uuid('member_party_id')->nullable()->index();
            $t->string('member_reference', 120)->nullable();
            $t->uuid('policy_id')->nullable()->index();
            $t->uuid('claim_id')->nullable()->index(); // member-level Claim on the claims engine
            $t->uuid('preauth_id')->nullable();
            $t->boolean('preauth_verified')->default(false);
            $t->date('service_date');
            $t->string('currency', 3);
            $t->string('status', 24)->default('DRAFT');
            $t->string('status_before_dispute', 24)->nullable();
            $t->bigInteger('billed_minor')->default(0);
            $t->bigInteger('allowed_minor')->default(0);
            $t->bigInteger('copay_minor')->default(0);
            $t->bigInteger('insurer_share_minor')->default(0);
            $t->bigInteger('member_share_minor')->default(0);
            $t->bigInteger('rejected_minor')->default(0);
            $t->uuid('financial_obligation_id')->nullable()->index();
            $t->uuid('settlement_batch_id')->nullable()->index();
            $t->uuid('dispute_case_id')->nullable();
            $t->text('dispute_reason')->nullable();
            $t->text('adjudication_note')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->timestampTz('adjudicated_at')->nullable();
            $t->uuid('adjudicated_by')->nullable();
            $t->timestampTz('paid_at')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'provider_profile_id', 'invoice_reference']);
            $t->index(['tenant_id', 'provider_profile_id', 'status']);
        });

        Schema::create('health_provider_claim_lines', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('health_provider_claim_id')->constrained('health_provider_claims')->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('medical_service_id')->constrained('medical_services');
            $t->string('provider_code', 64)->nullable();
            $t->unsignedInteger('quantity')->default(1);
            $t->bigInteger('unit_price_minor');
            $t->bigInteger('billed_minor');
            $t->uuid('tariff_line_id')->nullable();
            $t->unsignedInteger('tariff_version')->nullable();
            $t->bigInteger('tariff_unit_price_minor')->nullable();
            $t->bigInteger('allowed_minor')->default(0);
            $t->bigInteger('copay_minor')->default(0);
            $t->bigInteger('insurer_share_minor')->default(0);
            $t->bigInteger('member_share_minor')->default(0);
            $t->bigInteger('rejected_minor')->default(0);
            $t->string('decision', 24)->nullable(); // APPROVED | PARTIALLY_APPROVED | REJECTED
            $t->string('reason_code', 48)->nullable();
            $t->text('explanation')->nullable();
            $t->timestampsTz();
            $t->unique(['health_provider_claim_id', 'line_no']);
        });

        Schema::create('health_provider_claim_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('health_provider_claim_id')->constrained('health_provider_claims')->cascadeOnDelete();
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24);
            $t->string('event', 48);
            $t->text('reason')->nullable();
            $t->jsonb('payload')->default('{}');
            $t->uuid('actor_id')->nullable();
            $t->timestampTz('created_at');
        });

        Schema::create('health_provider_settlement_batches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('batch_number', 64)->unique();
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->string('currency', 3);
            $t->string('status', 24)->default('OPEN'); // OPEN | PAID | CANCELLED
            $t->bigInteger('total_minor')->default(0);
            $t->unsignedInteger('claim_count')->default(0);
            $t->string('payment_reference', 120)->nullable();
            $t->timestampTz('paid_at')->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('paid_by')->nullable();
            $t->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE health_provider_claims ADD CONSTRAINT health_provider_claims_status_chk CHECK (status IN ('DRAFT','SUBMITTED','UNDER_REVIEW','APPROVED','PARTIALLY_APPROVED','REJECTED','PAYABLE','PAID','DISPUTED'))");
            DB::statement("ALTER TABLE health_provider_settlement_batches ADD CONSTRAINT health_provider_settlement_batches_status_chk CHECK (status IN ('OPEN','PAID','CANCELLED'))");
            DB::statement('ALTER TABLE health_provider_claim_lines ADD CONSTRAINT health_provider_claim_lines_amounts_chk CHECK (quantity > 0 AND unit_price_minor >= 0 AND allowed_minor <= billed_minor AND copay_minor <= allowed_minor AND insurer_share_minor <= allowed_minor AND rejected_minor = billed_minor - allowed_minor)');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION health_provider_claim_events_immutable() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'health_provider_claim_events is append-only'; END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER health_provider_claim_events_no_change BEFORE UPDATE OR DELETE ON health_provider_claim_events
                    FOR EACH ROW EXECUTE FUNCTION health_provider_claim_events_immutable();
            SQL);
        }

        $now = now();
        foreach (self::EVENTS as $code => [$category, $debit, $credit, $description]) {
            if (DB::table('accounting_events')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('accounting_events')->insert(['code' => $code, 'category' => $category, 'description' => $description, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('accounting_event_mappings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'event_code' => $code, 'version' => 1, 'debit_account_code' => $debit,
                'credit_account_code' => $credit, 'status' => 'ACTIVE', 'reason' => 'Default OHADA/CIMA chart', 'effective_from' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('accounting_event_mappings')->whereNull('tenant_id')->whereIn('event_code', array_keys(self::EVENTS))->delete();
        DB::table('accounting_events')->whereIn('code', array_keys(self::EVENTS))->delete();
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS health_provider_claim_events_no_change ON health_provider_claim_events; DROP FUNCTION IF EXISTS health_provider_claim_events_immutable();');
        }
        foreach (['health_provider_claim_events', 'health_provider_claim_lines', 'health_provider_settlement_batches', 'health_provider_claims'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
