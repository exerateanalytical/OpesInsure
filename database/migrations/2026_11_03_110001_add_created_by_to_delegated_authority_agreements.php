<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Four-eyes on delegated-authority agreements: record the maker so the approver can be refused when it is the same user.
 * Additive and production-safe: nullable, no backfill (existing rows have no known maker and stay approvable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('delegated_authority_agreements', 'created_by')) {
            Schema::table('delegated_authority_agreements', function (Blueprint $t) {
                $t->foreignUuid('created_by')->nullable()->constrained('users');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('delegated_authority_agreements', 'created_by')) {
            Schema::table('delegated_authority_agreements', function (Blueprint $t) {
                $t->dropConstrainedForeignId('created_by');
            });
        }
    }
};
