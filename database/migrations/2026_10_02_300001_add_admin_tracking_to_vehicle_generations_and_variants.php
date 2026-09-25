<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CUST-007: generations / variants become admin-maintained (they ship empty;
 * no invented data). Additive: admin_modified_at like makes/models, and
 * year bounds on variants for model-year matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_generations', function (Blueprint $t) {
            $t->timestamp('admin_modified_at')->nullable();
            $t->index(['model_id', 'active']);
        });
        Schema::table('vehicle_variants', function (Blueprint $t) {
            $t->unsignedSmallInteger('year_from')->nullable();
            $t->unsignedSmallInteger('year_to')->nullable();
            $t->timestamp('admin_modified_at')->nullable();
            $t->index(['generation_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_variants', function (Blueprint $t) {
            $t->dropIndex(['generation_id', 'active']);
            $t->dropColumn(['year_from', 'year_to', 'admin_modified_at']);
        });
        Schema::table('vehicle_generations', function (Blueprint $t) {
            $t->dropIndex(['model_id', 'active']);
            $t->dropColumn('admin_modified_at');
        });
    }
};
