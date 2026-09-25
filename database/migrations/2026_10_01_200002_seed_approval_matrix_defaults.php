<?php

declare(strict_types=1);

use App\Application\Approvals\ApprovalActionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** REQ-RBAC-005/006: platform-default matrix rows + SoD pairs from the catalogue (additive, idempotent). */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (ApprovalActionCatalogue::ACTIONS as $code => $a) {
            if (DB::table('approval_matrix_rules')->whereNull('tenant_id')->where('action_code', $code)->exists()) {
                continue;
            }
            DB::table('approval_matrix_rules')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => null, 'action_code' => $code, 'workflow' => $a['workflow'], 'category' => $a['category'],
                'description' => $a['description'], 'source_refs' => $a['sources'], 'checker_permission' => $a['checker_permission'] ?? null,
                'required_approvals' => 1, 'requires_maker_checker' => true, 'exclude_subject_parties' => true, 'priority' => 1000,
                'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach (ApprovalActionCatalogue::SOD_CONFLICTS as [$first, $second, $description]) {
            DB::table('sod_conflict_rules')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'first_action' => $first, 'second_action' => $second, 'scope' => 'SUBJECT',
                'description' => $description, 'source_refs' => 'ICE gap 30; FRP VI', 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Reference data is never deleted.
    }
};
