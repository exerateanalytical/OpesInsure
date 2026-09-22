<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('party_identifiers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('party_id')->constrained()->cascadeOnDelete();
            $t->string('type', 32);
            $t->string('country_code', 2)->default('CM');
            $t->string('value_hash', 64);
            $t->text('value_encrypted');
            $t->string('masked_value', 80);
            $t->timestampTz('verified_at')->nullable();
            $t->foreignUuid('verified_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->unique(['type', 'country_code', 'value_hash']);
        });
        Schema::create('customer_status_history', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_customer_id')->constrained()->cascadeOnDelete();
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24);
            $t->string('reason_code', 64);
            $t->text('notes');
            $t->foreignUuid('actor_id')->constrained('users');
            $t->timestampTz('occurred_at');
        });
        Schema::table('consents', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->nullable()->after('party_id')->constrained();
            $t->foreignUuid('captured_by')->nullable()->after('channel')->constrained('users');
            $t->string('evidence_hash', 64)->nullable()->after('evidence');
        });
        Schema::create('consent_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('consent_id')->constrained()->cascadeOnDelete();
            $t->string('from_status', 16)->nullable();
            $t->string('to_status', 16);
            $t->string('reason_code', 64);
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->jsonb('evidence')->default('{}');
            $t->string('evidence_hash', 64);
            $t->timestampTz('occurred_at');
        });
        Schema::create('partner_status_history', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('partner_id')->constrained()->cascadeOnDelete();
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24);
            $t->string('reason_code', 64);
            $t->text('notes');
            $t->foreignUuid('actor_id')->constrained('users');
            $t->timestampTz('occurred_at');
        });
        Schema::create('attribution_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('attribution_id')->constrained('customer_attributions')->cascadeOnDelete();
            $t->string('type', 32);
            $t->foreignUuid('from_partner_id')->nullable()->constrained('partners');
            $t->foreignUuid('to_partner_id')->nullable()->constrained('partners');
            $t->foreignUuid('dispute_id')->nullable()->constrained('attribution_disputes');
            $t->string('reason_code', 64);
            $t->text('notes');
            $t->foreignUuid('actor_id')->constrained('users');
            $t->timestampTz('occurred_at');
        });
        DB::statement("CREATE UNIQUE INDEX customer_attributions_one_active_origin ON customer_attributions (party_id) WHERE status = 'ACTIVE'");
        DB::statement("ALTER TABLE consents ADD CONSTRAINT consent_status_allowed CHECK (status IN ('GRANTED','WITHDRAWN','EXPIRED'))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customer_attributions_one_active_origin');
        DB::statement('ALTER TABLE consents DROP CONSTRAINT IF EXISTS consent_status_allowed');
        Schema::dropIfExists('attribution_events');
        Schema::dropIfExists('partner_status_history');
        Schema::dropIfExists('consent_events');
        Schema::table('consents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tenant_id');
            $t->dropConstrainedForeignId('captured_by');
            $t->dropColumn('evidence_hash');
        });
        Schema::dropIfExists('customer_status_history');
        Schema::dropIfExists('party_identifiers');
    }
};
