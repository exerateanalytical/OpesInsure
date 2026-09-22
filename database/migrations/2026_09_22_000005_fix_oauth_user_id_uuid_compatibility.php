<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Passport's stock migrations declare user_id as foreignId (bigint), which
 * assumes Laravel's classic auto-increment user primary key. This app's
 * users.id is a UUID (HasUuids). This was never hit before because the only
 * tokens issued so far are client-credentials grant tokens for integration
 * partners (Batch 1), which always have a NULL user_id — inserting a real
 * user's UUID into these columns would fail outright. Mobile personal-access
 * tokens (issued directly for a human User after OTP verification) are the
 * first thing in this app that actually needs a non-null user_id here.
 *
 * All three tables are effectively empty of real user-bound rows today (no
 * personal-access or authorization-code token has ever been issued to a
 * User), so a straight type change is safe — nothing to migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE oauth_access_tokens ALTER COLUMN user_id TYPE uuid USING NULL');
        DB::statement('ALTER TABLE oauth_auth_codes ALTER COLUMN user_id TYPE uuid USING NULL');
        DB::statement('ALTER TABLE oauth_device_codes ALTER COLUMN user_id TYPE uuid USING NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE oauth_access_tokens ALTER COLUMN user_id TYPE bigint USING NULL');
        DB::statement('ALTER TABLE oauth_auth_codes ALTER COLUMN user_id TYPE bigint USING NULL');
        DB::statement('ALTER TABLE oauth_device_codes ALTER COLUMN user_id TYPE bigint USING NULL');
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
