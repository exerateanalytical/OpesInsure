<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Encrypted (APP_KEY) provider secrets on the payment connection, e.g. MTN MoMo subscription key / API user / API key. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_provider_connections', 'secrets')) {
            Schema::table('payment_provider_connections', fn (Blueprint $t) => $t->text('secrets')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_provider_connections', 'secrets')) {
            Schema::table('payment_provider_connections', fn (Blueprint $t) => $t->dropColumn('secrets'));
        }
    }
};
