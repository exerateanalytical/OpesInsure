<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuration screens (web UI phase 4). Additive only:
 *  - letterhead_assets: contact block (phone, email, website) and bilingual legal footer text, versioned with the artwork;
 *  - document_physical_security_assets: security_features (UV ink, hologram type, paper stock grade / weight ...) recorded
 *    by the owner for D6. Empty = nothing claimed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letterhead_assets', function (Blueprint $t) {
            $t->string('contact_phone', 32)->nullable();
            $t->string('contact_email', 190)->nullable();
            $t->string('website', 190)->nullable();
            $t->text('footer_text_en')->nullable();
            $t->text('footer_text_fr')->nullable();
        });
        Schema::table('document_physical_security_assets', function (Blueprint $t) {
            $t->jsonb('security_features')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('letterhead_assets', fn (Blueprint $t) => $t->dropColumn(['contact_phone', 'contact_email', 'website', 'footer_text_en', 'footer_text_fr']));
        Schema::table('document_physical_security_assets', fn (Blueprint $t) => $t->dropColumn('security_features'));
    }
};
