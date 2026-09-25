<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\CarrierOperations\Agreements\LegacyAgreementBackfill;
use Illuminate\Console\Command;

/** REQ-DUP-023 — refresh carrier_broker_agreements / authority_limits from the legacy delegated_authority_agreements table. */
final class SyncLegacyAgreements extends Command
{
    protected $signature = 'opesinsure:agreements:sync-legacy';

    protected $description = 'Copy delegated authority agreements into carrier_broker_agreements + authority_limits (idempotent).';

    public function handle(LegacyAgreementBackfill $backfill): int
    {
        $result = $backfill->run();
        $this->components->info("Agreements created: {$result['created']}, refreshed: {$result['refreshed']}.");

        return self::SUCCESS;
    }
}
