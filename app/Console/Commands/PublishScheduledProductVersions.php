<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Catalogue\Governance\ProductGovernanceService;
use Illuminate\Console\Command;

/** REQ-PRD-007 — future-dated publication (PRE §79): publishes READY versions whose scheduled time has come. */
final class PublishScheduledProductVersions extends Command
{
    protected $signature = 'catalogue:publish-scheduled';

    protected $description = 'Publish product versions whose governance-scheduled publication time has come';

    public function handle(ProductGovernanceService $governance): int
    {
        $done = $governance->publishDue();
        $this->info(count($done).' product version(s) published.');

        return self::SUCCESS;
    }
}
