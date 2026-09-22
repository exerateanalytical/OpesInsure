<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // client_secret_hash predates the Passport client-credentials wiring
    // (IntegrationClientLifecycleService::register()) and is no longer how
    // a client authenticates — oauth_client_id + Passport's own hashed
    // secret is. Kept only for display/legacy rows, so it must be nullable.
    public function up(): void
    {
        Schema::table('integration_clients', function (Blueprint $t) {
            $t->string('client_secret_hash')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('integration_clients', function (Blueprint $t) {
            $t->string('client_secret_hash')->nullable(false)->change();
        });
    }
};
