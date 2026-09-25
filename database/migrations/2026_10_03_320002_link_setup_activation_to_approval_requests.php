<?php

declare(strict_types=1);

use App\Application\Approvals\ApprovalActionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * REQ-SET-002 / REQ-SET-003 — insurer and broker activation go through the central approval
 * engine (REQ-RBAC-005/006): link the setup record to its approval request and seed the
 * platform-default matrix rows for the two new catalogue actions (additive, idempotent).
 */
return new class extends Migration
{
    private const ACTIONS = ['insurer_setup.activate', 'broker_setup.activate'];

    public function up(): void
    {
        Schema::table('carrier_setups', fn (Blueprint $t) => $t->foreignUuid('approval_request_id')->nullable()->constrained('approval_requests'));
        Schema::table('partner_setups', fn (Blueprint $t) => $t->foreignUuid('approval_request_id')->nullable()->constrained('approval_requests'));

        $now = now();
        foreach (self::ACTIONS as $code) {
            $a = ApprovalActionCatalogue::ACTIONS[$code];
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
    }

    public function down(): void
    {
        Schema::table('partner_setups', fn (Blueprint $t) => $t->dropConstrainedForeignId('approval_request_id'));
        Schema::table('carrier_setups', fn (Blueprint $t) => $t->dropConstrainedForeignId('approval_request_id'));
        // Matrix reference rows are never deleted.
    }
};
