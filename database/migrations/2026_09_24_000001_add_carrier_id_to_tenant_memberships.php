<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an insurer (CARRIER_ADMIN / CARRIER_STAFF) membership to the carrier
 * whose data it may see, so /mobile/carrier/* can be scoped to one insurer
 * instead of returning tenant-wide data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $t): void {
            $t->foreignUuid('carrier_id')->nullable()->after('branch_id')->constrained('carriers')->nullOnDelete();
        });
        // An insurer invitation carries the carrier its membership will be linked to.
        Schema::table('tenant_invitations', function (Blueprint $t): void {
            $t->foreignUuid('carrier_id')->nullable()->after('role_code')->constrained('carriers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_invitations', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('carrier_id');
        });
        Schema::table('tenant_memberships', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('carrier_id');
        });
    }
};
