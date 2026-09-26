<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Append order for control assessments: assessed_at has second precision, so two assessments in the same second tied. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('compliance_control_assessments', 'seq')) {
            DB::statement('ALTER TABLE compliance_control_assessments ADD COLUMN seq BIGSERIAL');
        }
    }

    public function down(): void {}
};
