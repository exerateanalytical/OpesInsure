<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Screening\Console;

use App\Application\Compliance\Aml\Screening\ScreeningService;
use Illuminate\Console\Command;

/** Agent E8 — REQ-AML-001 periodic rescreen of tenant customers (interval aml.screening.rescreen_interval_days; NULL = off). */
final class AmlRescreenCommand extends Command
{
    protected $signature = 'aml:rescreen {--tenant= : Rescreen every customer of this tenant now}';

    protected $description = 'Rescreen parties against the active PEP / sanctions / watchlist versions.';

    public function handle(ScreeningService $screening): int
    {
        $n = $this->option('tenant')
            ? $screening->rescreenTenant((string) $this->option('tenant'), 'MANUAL_RESCREEN')
            : $screening->rescreenDue();
        $this->info("Parties screened: {$n}");

        return self::SUCCESS;
    }
}
