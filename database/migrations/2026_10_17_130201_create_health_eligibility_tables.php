<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 14A (agent E2) — REQ-HLT-001 health eligibility.
 *  - health_members: insured persons of a health policy — the PRINCIPAL (policyholder), DEPENDANTs
 *    (spouse/child/other, linked to a principal) and GROUP_MEMBERs (linked to a Batch 8-6
 *    policy_schedule_items GROUP_MEMBER row). Dated [effective_from, effective_to).
 *  - health_member_cards: digital health cards. Only the sha256 of the QR token is stored
 *    (same approach as certificate verification tokens); reissue revokes the previous card.
 *  - health_policy_networks: which Batch 13A provider networks a health policy may use.
 *  - health_benefit_rules: benefit schedule lookup — medical service (code or category) → policy
 *    coverage_code + benefit_code + optional waiting period (days). policy_id NULL = tenant default.
 *    No waiting period is seeded: waiting periods are contract/product terms (owner question).
 *  - health_eligibility_checks: append-only log of every eligibility check (DB trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->string('member_number', 40);
            $t->string('card_number', 40);
            $t->string('relationship', 16);                    // PRINCIPAL | SPOUSE | CHILD | DEPENDANT | GROUP_MEMBER
            $t->uuid('principal_member_id')->nullable();
            $t->foreignUuid('party_id')->nullable()->constrained('parties');
            $t->foreignUuid('schedule_item_id')->nullable()->constrained('policy_schedule_items');
            $t->string('display_name', 191);
            $t->date('date_of_birth')->nullable();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('status', 16)->default('ACTIVE');       // ACTIVE | SUSPENDED | ENDED
            $t->string('end_reason', 255)->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'member_number']);
            $t->unique(['tenant_id', 'card_number']);
            $t->index(['policy_id', 'status']);
        });
        Schema::table('health_members', fn (Blueprint $t) => $t->foreign('principal_member_id')->references('id')->on('health_members'));
        DB::statement("ALTER TABLE health_members ADD CONSTRAINT health_members_relationship_allowed CHECK (relationship IN ('PRINCIPAL','SPOUSE','CHILD','DEPENDANT','GROUP_MEMBER'))");
        DB::statement("ALTER TABLE health_members ADD CONSTRAINT health_members_status_allowed CHECK (status IN ('ACTIVE','SUSPENDED','ENDED'))");
        DB::statement('ALTER TABLE health_members ADD CONSTRAINT health_members_dates CHECK (effective_to IS NULL OR effective_to > effective_from)');

        Schema::create('health_member_cards', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('health_member_id')->constrained('health_members');
            $t->unsignedInteger('card_version');
            $t->string('token_hash', 64);
            $t->string('status', 16)->default('ACTIVE');       // ACTIVE | REVOKED
            $t->timestampTz('issued_at');
            $t->foreignUuid('issued_by')->nullable()->constrained('users');
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampsTz();
            $t->unique(['health_member_id', 'card_version']);
        });
        DB::statement("CREATE UNIQUE INDEX health_member_cards_one_active ON health_member_cards (health_member_id) WHERE status = 'ACTIVE'");

        Schema::create('health_policy_networks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->foreignUuid('provider_network_id')->constrained('provider_networks');
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->unique(['policy_id', 'provider_network_id']);
        });

        Schema::create('health_benefit_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->nullable()->constrained('policies');
            $t->string('medical_service_code', 64)->nullable();
            $t->string('service_category_code', 64)->nullable();
            $t->string('coverage_code', 64);
            $t->string('benefit_code', 64);
            $t->unsignedInteger('waiting_period_days')->nullable();
            $t->string('status', 16)->default('ACTIVE');
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->index(['tenant_id', 'policy_id']);
        });
        DB::statement('ALTER TABLE health_benefit_rules ADD CONSTRAINT health_benefit_rules_target CHECK (medical_service_code IS NOT NULL OR service_category_code IS NOT NULL)');

        Schema::create('health_eligibility_checks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('health_member_id')->nullable();
            $t->string('member_ref_hash', 64);
            $t->uuid('policy_id')->nullable();
            $t->uuid('policy_version_id')->nullable();
            $t->uuid('provider_profile_id')->nullable();
            $t->string('service_code', 64)->nullable();
            $t->date('service_date');
            $t->string('coverage_code', 64)->nullable();
            $t->string('benefit_code', 64)->nullable();
            $t->string('outcome', 32);
            $t->jsonb('reasons');
            $t->string('channel', 16);                          // API | SCAN | INTERNAL
            $t->uuid('actor_id')->nullable();
            $t->timestampTz('checked_at');
            $t->index(['tenant_id', 'health_member_id', 'checked_at']);
        });
        DB::statement("ALTER TABLE health_eligibility_checks ADD CONSTRAINT health_eligibility_checks_outcome_allowed CHECK (outcome IN ('ELIGIBLE','NOT_ELIGIBLE','WAITING_PERIOD','BENEFIT_EXHAUSTED','PROVIDER_NOT_IN_NETWORK','REVIEW_REQUIRED','CARD_NOT_VERIFIED'))");

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION health_eligibility_checks_immutable() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'health_eligibility_checks is append-only'; END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER health_eligibility_checks_no_change BEFORE UPDATE OR DELETE ON health_eligibility_checks
                    FOR EACH ROW EXECUTE FUNCTION health_eligibility_checks_immutable();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS health_eligibility_checks_no_change ON health_eligibility_checks; DROP FUNCTION IF EXISTS health_eligibility_checks_immutable();');
        }
        Schema::dropIfExists('health_eligibility_checks');
        Schema::dropIfExists('health_benefit_rules');
        Schema::dropIfExists('health_policy_networks');
        Schema::dropIfExists('health_member_cards');
        Schema::dropIfExists('health_members');
    }
};
