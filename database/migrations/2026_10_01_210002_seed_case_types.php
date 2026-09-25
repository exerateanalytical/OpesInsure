<?php

declare(strict_types=1);

use App\Application\Cases\CaseTypeCatalogue;
use Illuminate\Database\Migrations\Migration;

/**
 * REQ-CAS-001: v1 of the ICE §6.2 case types (as sub-types; family_code NULL
 * pending OQ-6.4). Insert-only and idempotent; never overwrites a version.
 */
return new class extends Migration
{
    public function up(): void
    {
        CaseTypeCatalogue::seed();
    }

    public function down(): void
    {
        // Reference data: never deleted.
    }
};
