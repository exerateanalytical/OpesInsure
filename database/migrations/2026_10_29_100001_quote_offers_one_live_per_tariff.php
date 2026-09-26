<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * quote_offers_one_per_tariff counted SUPERSEDED offers too, so re-rating an amended quote (PATCH /quotes/{id} →
 * POST /quotes/{id}/rate) hit a unique violation. The rule is one LIVE offer per (quote, tariff version); superseded,
 * expired, declined, withdrawn and not-selected offers stay as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS quote_offers_one_per_tariff');
        DB::statement("CREATE UNIQUE INDEX quote_offers_one_per_tariff ON quote_offers (quote_id, tariff_version_id) WHERE status IN ('OFFERED', 'ACCEPTED')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS quote_offers_one_per_tariff');
        DB::statement('CREATE UNIQUE INDEX quote_offers_one_per_tariff ON quote_offers (quote_id, tariff_version_id)');
    }
};
