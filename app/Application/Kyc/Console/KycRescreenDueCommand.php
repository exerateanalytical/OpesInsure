<?php

declare(strict_types=1);

namespace App\Application\Kyc\Console;

use App\Application\Kyc\KycService;
use Illuminate\Console\Command;

/**
 * Owner decision 27: opens a MANUAL_AUDITED rescreening round for approved KYC whose risk-based rescreening date
 * has passed (config kyc.risk.rescreen_months; NULL = never scheduled). Scheduled daily.
 */
final class KycRescreenDueCommand extends Command
{
    protected $signature = 'kyc:rescreen-due';

    protected $description = 'Open manual (audited) rescreening rounds for approved KYC that are due.';

    public function handle(KycService $kyc): int
    {
        $this->info('Rescreening rounds opened: '.$kyc->rescreenDue());

        return self::SUCCESS;
    }
}
