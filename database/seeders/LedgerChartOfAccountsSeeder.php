<?php

namespace Database\Seeders;

use App\Application\Ledger\Posting\AccountingEventMappingService;
use Illuminate\Database\Seeder;

/** REQ-ACC-001: provisions the default OHADA/CIMA platform chart (XAF). Tenant charts are provisioned lazily on first posting. */
final class LedgerChartOfAccountsSeeder extends Seeder
{
    public function run(AccountingEventMappingService $mappings): void
    {
        $mappings->provisionChart(null, 'XAF');
    }
}
