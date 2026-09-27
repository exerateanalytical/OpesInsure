<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issuance maker-checker stages (mobile audit E2): a checker may send a pending issuance request back for
 * correction, verify it, and — when the first approver's POLICY_ISSUE authority is exceeded — record a first
 * approval that a second, different approver completes. Nobody may check their own request, and the second
 * approver must differ from the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_issuance_requests', function (Blueprint $t): void {
            $t->foreignUuid('verified_by')->nullable()->constrained('users');
            $t->timestampTz('verified_at')->nullable();
            $t->foreignUuid('first_approved_by')->nullable()->constrained('users');
            $t->timestampTz('first_approved_at')->nullable();
            $t->text('correction_reason')->nullable();
            $t->foreignUuid('correction_requested_by')->nullable()->constrained('users');
            $t->timestampTz('correction_requested_at')->nullable();
        });
        DB::statement('ALTER TABLE policy_issuance_requests ADD CONSTRAINT issuance_verifier_separation CHECK (verified_by IS NULL OR verified_by <> requested_by)');
        DB::statement('ALTER TABLE policy_issuance_requests ADD CONSTRAINT issuance_first_approver_separation CHECK (first_approved_by IS NULL OR first_approved_by <> requested_by)');
        DB::statement('ALTER TABLE policy_issuance_requests ADD CONSTRAINT issuance_second_approver_separation CHECK (first_approved_by IS NULL OR approved_by IS NULL OR approved_by <> first_approved_by)');
    }

    public function down(): void
    {
        foreach (['issuance_verifier_separation', 'issuance_first_approver_separation', 'issuance_second_approver_separation'] as $c) {
            DB::statement("ALTER TABLE policy_issuance_requests DROP CONSTRAINT IF EXISTS {$c}");
        }
        Schema::table('policy_issuance_requests', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('verified_by');
            $t->dropConstrainedForeignId('first_approved_by');
            $t->dropConstrainedForeignId('correction_requested_by');
            $t->dropColumn(['verified_at', 'first_approved_at', 'correction_reason', 'correction_requested_at']);
        });
    }
};
