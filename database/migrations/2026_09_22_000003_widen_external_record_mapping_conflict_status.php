<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // 24 chars is too tight for a real conflict reason code (e.g.
    // PARTNER_CHANGED_FIELD_WE_OWN is 28) — match the 64-char convention
    // reason_code columns use elsewhere in this app.
    public function up(): void
    {
        Schema::table('external_record_mappings', function (Blueprint $t) {
            $t->string('conflict_status', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('external_record_mappings', function (Blueprint $t) {
            $t->string('conflict_status', 24)->nullable()->change();
        });
    }
};
