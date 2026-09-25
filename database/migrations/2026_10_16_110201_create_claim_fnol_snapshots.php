<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-CLM-002 (WF-048/049) — immutable FNOL snapshot: the policy version in force at the loss
 * date, the facts as reported, who reported them (incl. an agent acting for the customer,
 * AGT-052) and through which channel. One row per claim, written once by FnolService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_fnol_snapshots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('claim_id')->unique()->constrained('claims');
            $t->string('claim_number', 64);
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->foreignUuid('policy_version_id')->nullable()->constrained('policy_versions');
            $t->unsignedInteger('policy_version_no')->nullable();
            $t->jsonb('policy_snapshot');
            $t->jsonb('reported_facts');
            $t->string('channel', 24);
            $t->foreignUuid('reporter_user_id')->nullable()->constrained('users');
            $t->uuid('reporter_party_id')->nullable();
            $t->string('reporter_role', 24);
            $t->uuid('acting_partner_id')->nullable();
            $t->foreignUuid('claimant_party_id')->constrained('parties');
            $t->uuid('capability_pin_id')->nullable();
            $t->string('content_hash', 64);
            $t->timestampTz('loss_occurred_at');
            $t->timestampTz('reported_at');
            $t->timestampTz('created_at');
            $t->index(['tenant_id', 'channel']);
            $t->index(['acting_partner_id']);
        });
        DB::statement("ALTER TABLE claim_fnol_snapshots ADD CONSTRAINT claim_fnol_snapshots_channel CHECK (channel IN ('MOBILE','WEB','AGENT','BACK_OFFICE','PHONE','EMAIL','BRANCH','API'))");
        DB::statement("ALTER TABLE claim_fnol_snapshots ADD CONSTRAINT claim_fnol_snapshots_role CHECK (reporter_role IN ('CUSTOMER','AGENT','STAFF','SYSTEM'))");
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION claim_fnol_snapshots_immutable() RETURNS trigger AS $$
BEGIN RAISE EXCEPTION 'claim_fnol_snapshots rows are immutable'; END; $$ LANGUAGE plpgsql;
CREATE TRIGGER claim_fnol_snapshots_immutable BEFORE UPDATE OR DELETE ON claim_fnol_snapshots FOR EACH ROW EXECUTE FUNCTION claim_fnol_snapshots_immutable();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS claim_fnol_snapshots_immutable ON claim_fnol_snapshots; DROP FUNCTION IF EXISTS claim_fnol_snapshots_immutable();');
        }
        Schema::dropIfExists('claim_fnol_snapshots');
    }
};
