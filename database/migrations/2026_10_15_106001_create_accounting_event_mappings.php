<?php

use App\Application\Ledger\Posting\DefaultChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** REQ-ACC-001: accounting event catalogue + per-tenant versioned event -> GL account mapping. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_events', function (Blueprint $t) {
            $t->string('code', 64)->primary();
            $t->string('category', 24);
            $t->string('description');
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampsTz();
        });
        Schema::create('accounting_event_mappings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->string('event_code', 64);
            $t->foreign('event_code')->references('code')->on('accounting_events');
            $t->unsignedInteger('version');
            $t->string('debit_account_code', 64);
            $t->string('credit_account_code', 64);
            $t->string('status', 16)->default('ACTIVE');
            $t->string('reason')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampTz('effective_from');
            $t->timestampTz('superseded_at')->nullable();
            $t->timestampsTz();
            $t->index(['event_code', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX accounting_event_mappings_version ON accounting_event_mappings (COALESCE(tenant_id::text, '*'), event_code, version)");
        DB::statement("CREATE UNIQUE INDEX accounting_event_mappings_one_active ON accounting_event_mappings (COALESCE(tenant_id::text, '*'), event_code) WHERE status = 'ACTIVE'");
        DB::statement('ALTER TABLE accounting_event_mappings ADD CONSTRAINT accounting_event_mappings_distinct CHECK (debit_account_code <> credit_account_code)');

        $now = now();
        foreach (DefaultChartOfAccounts::EVENTS as $code => [$category, $debit, $credit, $description]) {
            DB::table('accounting_events')->insert(['code' => $code, 'category' => $category, 'description' => $description, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('accounting_event_mappings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'event_code' => $code, 'version' => 1, 'debit_account_code' => $debit,
                'credit_account_code' => $credit, 'status' => 'ACTIVE', 'reason' => 'Default OHADA/CIMA chart', 'effective_from' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_event_mappings');
        Schema::dropIfExists('accounting_events');
    }
};
