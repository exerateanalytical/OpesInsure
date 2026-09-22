<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passport 13 has no password grant, so MobileAuthService issues Passport
 * personal-access tokens directly after OTP verification — but personal
 * access tokens don't have Passport's own refresh-token machinery attached.
 * This table implements rotation + replay-family revocation ourselves:
 * family_id groups a device's whole rotation chain, and presenting an
 * already-rotated (replaced_at set) token is a reuse signal that revokes
 * every row in that family, not just the one presented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_refresh_tokens', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('device_id')->nullable()->constrained('user_devices')->nullOnDelete();
            $t->uuid('family_id');
            $t->string('token_hash', 64)->unique();
            $t->string('access_token_id', 80)->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampTz('replaced_at')->nullable();
            $t->timestampsTz();
            $t->index('family_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_refresh_tokens');
    }
};
