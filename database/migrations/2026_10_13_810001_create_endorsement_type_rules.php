<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * REQ-END-001 — endorsement rules per type (WF-035..038) + endorsement columns on policy_transactions.
 * REQ-DUP-014 — idempotency for the one canonical customer service-request intake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endorsement_type_rules', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();   // NULL = platform default
            $t->string('code', 32);
            $t->string('name', 120);
            $t->jsonb('change_paths')->default('[]');                  // allowed dot-path prefixes in requested_changes; "*" = any
            $t->string('financial_effect', 16);                         // NONE | RERATE | MANUAL
            $t->integer('max_backdate_days')->default(0);
            $t->jsonb('required_documents')->default('[]');
            $t->boolean('requires_authority')->default(false);          // ENDORSE authority (owner decision 12)
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });
        DB::statement("ALTER TABLE endorsement_type_rules ADD CONSTRAINT endorsement_rule_effect_valid CHECK (financial_effect IN ('NONE','RERATE','MANUAL'))");
        DB::statement('CREATE UNIQUE INDEX endorsement_type_rules_platform_code ON endorsement_type_rules (code) WHERE tenant_id IS NULL');

        $now = now();
        foreach ([
            ['GENERAL', 'General endorsement', ['*'], 'MANUAL', 365, [], false],
            ['ADDRESS_CHANGE', 'Address change', ['insured.address', 'risk_facts.address', 'contact'], 'NONE', 30, [], false],
            ['BENEFICIARY_CHANGE', 'Beneficiary change', ['beneficiaries'], 'NONE', 0, ['BENEFICIARY_CHANGE_REQUEST'], false],
            ['VEHICLE_CHANGE', 'Vehicle replacement', ['risk_facts', 'vehicle'], 'RERATE', 0, ['VEHICLE_REGISTRATION'], true],
            ['SUM_INSURED_CHANGE', 'Sum insured change', ['risk_facts', 'coverage_snapshot'], 'RERATE', 0, [], true],
            ['COVER_ADD', 'Add cover', ['coverage_snapshot', 'risk_facts'], 'RERATE', 0, [], true],
            ['COVER_REMOVE', 'Remove cover', ['coverage_snapshot', 'risk_facts'], 'RERATE', 0, [], true],
        ] as [$code, $name, $paths, $effect, $backdate, $docs, $auth]) {
            DB::table('endorsement_type_rules')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => null, 'code' => $code, 'name' => $name,
                'change_paths' => json_encode($paths), 'financial_effect' => $effect, 'max_backdate_days' => $backdate,
                'required_documents' => json_encode($docs), 'requires_authority' => $auth, 'status' => 'ACTIVE',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('policy_transactions', function (Blueprint $t): void {
            $t->string('endorsement_type', 32)->nullable();
            $t->string('financial_effect', 24)->nullable();              // ADDITIONAL_PREMIUM | REFUND | NIL
            $t->jsonb('rating_basis')->nullable();                        // rerate trace (method, tariff, before/after, pro-rata)
            $t->uuid('policy_version_id')->nullable();
            $t->uuid('authority_check_id')->nullable();
            $t->uuid('refund_id')->nullable();
            $t->string('channel', 16)->nullable();
            $t->string('idempotency_key', 128)->nullable();
            $t->uuid('service_request_id')->nullable();                  // customer request this endorsement was raised from
            $t->unique(['tenant_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('policy_transactions', function (Blueprint $t): void {
            $t->dropUnique(['tenant_id', 'idempotency_key']);
            $t->dropColumn(['endorsement_type', 'financial_effect', 'rating_basis', 'policy_version_id', 'authority_check_id', 'refund_id', 'channel', 'idempotency_key', 'service_request_id']);
        });
        Schema::dropIfExists('endorsement_type_rules');
    }
};
