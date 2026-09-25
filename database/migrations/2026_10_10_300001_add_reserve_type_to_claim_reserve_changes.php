<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow data master (claims.reserve_types): a reserve change may say which
 * reserve it moves (INDEMNITY, LEGAL, ADJUSTER, …). Nullable and not
 * backfilled: existing rows keep their meaning (unclassified).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('claim_reserve_changes', 'reserve_type')) {
            Schema::table('claim_reserve_changes', function (Blueprint $t): void {
                $t->string('reserve_type', 24)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('claim_reserve_changes', 'reserve_type')) {
            Schema::table('claim_reserve_changes', fn (Blueprint $t) => $t->dropColumn('reserve_type'));
        }
    }
};
