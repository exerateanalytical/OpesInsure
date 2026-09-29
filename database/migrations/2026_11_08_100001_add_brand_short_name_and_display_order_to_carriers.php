<?php

declare(strict_types=1);

use App\Application\Directory\InsurerShortNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-09-29: cards show the insurer's short brand name, lists follow the
 * owner's display order. Additive columns; legal_name / register short_name untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carriers', function (Blueprint $t) {
            if (! Schema::hasColumn('carriers', 'brand_short_name')) {
                $t->string('brand_short_name', 64)->nullable();
            }
            if (! Schema::hasColumn('carriers', 'display_order')) {
                $t->unsignedSmallInteger('display_order')->nullable()->index();
            }
        });

        InsurerShortNames::sync();
    }

    public function down(): void
    {
        Schema::table('carriers', fn (Blueprint $t) => $t->dropColumn(['brand_short_name', 'display_order']));
    }
};
