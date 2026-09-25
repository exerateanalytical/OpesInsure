<?php

declare(strict_types=1);

namespace App\Application\Rating\Console;

use App\Application\Rating\TariffGovernanceService;
use Illuminate\Console\Command;

/** REQ-RAT-002: daily sweep SCHEDULED → ACTIVE on effective_from, ACTIVE → EXPIRED after effective_until. */
final class TariffsAdvanceCommand extends Command
{
    protected $signature = 'tariffs:advance';

    protected $description = 'Activate scheduled tariff versions that became effective and expire ended ones.';

    public function handle(TariffGovernanceService $tariffs): int
    {
        $r = $tariffs->advanceDue();
        $this->info("Activated {$r['activated']}, expired {$r['expired']}.");

        return self::SUCCESS;
    }
}
