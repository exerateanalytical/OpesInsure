<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-COI-001 (FRP Part III, MPS §41): co-insurance arrangements — one apériteur
 * (LEAD) plus followers, shares in basis points, and immutable apportionment
 * records for premium / claim / commission / reserve / settlement amounts.
 * Deliberately separate from any reinsurance table: co-insurance is never reinsurance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coinsurance_arrangements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->nullable()->constrained('policies');
            $t->string('reference', 64);
            $t->string('currency', 3)->default('XAF');
            $t->string('status', 24)->default('DRAFT'); // DRAFT, ACTIVE, TERMINATED
            $t->boolean('allow_partial_placement')->default(false);
            $t->jsonb('lead_rights')->default('[]');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('activated_by')->nullable()->constrained('users');
            $t->timestampTz('activated_at')->nullable();
            $t->timestampTz('terminated_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'reference']);
            $t->index(['tenant_id', 'policy_id', 'status']);
        });
        Schema::create('coinsurance_participants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('arrangement_id')->constrained('coinsurance_arrangements')->cascadeOnDelete();
            $t->foreignUuid('carrier_id')->constrained('carriers');
            $t->string('role', 16); // LEAD, FOLLOWER
            $t->unsignedInteger('share_bps'); // 10000 = 100%
            $t->jsonb('share_overrides_bps')->default('{}'); // per basis, e.g. {"COMMISSION": 2500}
            $t->timestampsTz();
            $t->unique(['arrangement_id', 'carrier_id']);
        });
        DB::statement("CREATE UNIQUE INDEX coinsurance_participants_one_lead ON coinsurance_participants (arrangement_id) WHERE role = 'LEAD'");
        DB::statement('ALTER TABLE coinsurance_participants ADD CONSTRAINT coinsurance_participants_share_range CHECK (share_bps > 0 AND share_bps <= 10000)');
        Schema::create('coinsurance_apportionments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('arrangement_id')->constrained('coinsurance_arrangements');
            $t->unsignedInteger('arrangement_version');
            $t->string('basis', 16); // PREMIUM, CLAIM, COMMISSION, RESERVE, SETTLEMENT
            $t->string('source_type', 64);
            $t->uuid('source_id')->nullable();
            $t->bigInteger('total_minor');
            $t->string('currency', 3);
            $t->jsonb('lines');
            $t->string('idempotency_key', 128);
            $t->foreignUuid('recorded_by')->nullable()->constrained('users');
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['arrangement_id', 'basis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coinsurance_apportionments');
        Schema::dropIfExists('coinsurance_participants');
        Schema::dropIfExists('coinsurance_arrangements');
    }
};
