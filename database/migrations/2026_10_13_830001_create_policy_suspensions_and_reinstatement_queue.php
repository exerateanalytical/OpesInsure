<?php

use App\Application\Cases\CaseTypeCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * REQ-POL-006 (WF-046/047, BRK-063) — policy suspension & reinstatement.
 *
 *  policy_suspensions   one row per suspension episode; at most one open (SUSPENDED / REINSTATEMENT_REQUESTED) per policy.
 *  policy_versions.kind gains SUSPENSION (chronology records suspension as its own version).
 *  case_types POLICY_REINSTATEMENT v1 — the reinstatement work queue on the case engine (generic lifecycle, family OPERATIONS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_suspensions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained();
            $t->string('status', 32);
            $t->string('source', 32);
            $t->string('reason_code', 64);
            $t->text('notes')->nullable();
            $t->timestampTz('suspended_at');
            $t->foreignUuid('suspended_by')->nullable()->constrained('users');
            $t->uuid('suspension_version_id')->nullable();
            $t->string('reinstatement_reason_code', 64)->nullable();
            $t->foreignUuid('reinstatement_requested_by')->nullable()->constrained('users');
            $t->timestampTz('reinstatement_requested_at')->nullable();
            $t->uuid('reinstatement_case_id')->nullable();
            $t->foreignUuid('reinstated_by')->nullable()->constrained('users');
            $t->timestampTz('reinstated_at')->nullable();
            $t->uuid('reinstatement_version_id')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestampTz('ended_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE policy_suspensions ADD CONSTRAINT policy_suspensions_status_allowed CHECK (status IN ('SUSPENDED','REINSTATEMENT_REQUESTED','REINSTATED','ENDED'))");
        DB::statement("ALTER TABLE policy_suspensions ADD CONSTRAINT policy_suspensions_source_allowed CHECK (source IN ('MANUAL','PREMIUM_DEFAULT','COMPLIANCE','SYSTEM'))");
        DB::statement("CREATE UNIQUE INDEX policy_suspensions_one_open ON policy_suspensions (policy_id) WHERE status IN ('SUSPENDED','REINSTATEMENT_REQUESTED')");

        DB::statement('ALTER TABLE policy_versions DROP CONSTRAINT IF EXISTS policy_versions_kind_allowed');
        DB::statement("ALTER TABLE policy_versions ADD CONSTRAINT policy_versions_kind_allowed CHECK (kind IN ('ISSUANCE','ENDORSEMENT','RENEWAL','REINSTATEMENT','CANCELLATION','BACKFILL','SUSPENSION'))");

        if (! DB::table('case_types')->where('code', 'POLICY_REINSTATEMENT')->exists()) {
            $def = CaseTypeCatalogue::genericLifecycle(false);
            DB::table('case_types')->insert([
                'id' => (string) Str::uuid(), 'code' => 'POLICY_REINSTATEMENT', 'version' => 1, 'family_code' => 'OPERATIONS',
                'name' => 'Policy reinstatement', 'status' => 'EFFECTIVE', 'valid_from' => '2026-01-01', 'valid_to' => null,
                'states' => json_encode($def['states']), 'transitions' => json_encode($def['transitions']),
                'sla_policies' => '[]', 'auto_tasks' => '[]', 'default_confidentiality' => 'NORMAL', 'regulated' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $types = DB::table('case_types')->where('code', 'POLICY_REINSTATEMENT')->pluck('id');
        if ($types->isNotEmpty() && ! DB::table('cases')->whereIn('case_type_id', $types)->exists()) {
            DB::table('case_types')->whereIn('id', $types)->delete();
        }
        DB::statement('ALTER TABLE policy_versions DROP CONSTRAINT IF EXISTS policy_versions_kind_allowed');
        DB::statement("ALTER TABLE policy_versions ADD CONSTRAINT policy_versions_kind_allowed CHECK (kind IN ('ISSUANCE','ENDORSEMENT','RENEWAL','REINSTATEMENT','CANCELLATION','BACKFILL')) NOT VALID");
        Schema::dropIfExists('policy_suspensions');
    }
};
