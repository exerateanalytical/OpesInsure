<?php

declare(strict_types=1);

namespace App\Application\Cases\Console;

use App\Application\Cases\Sla\SlaService;
use Illuminate\Console\Command;

/** REQ-CAS-001 SlaService::tick() — scheduled every minute by CasesServiceProvider. */
final class SlaTickCommand extends Command
{
    protected $signature = 'cases:sla-tick';

    protected $description = 'Warn, breach and escalate case SLA clocks; flag overdue tasks, due follow-ups and orphaned cases.';

    public function handle(SlaService $sla): int
    {
        $r = $sla->tick();
        $this->line(json_encode($r, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
