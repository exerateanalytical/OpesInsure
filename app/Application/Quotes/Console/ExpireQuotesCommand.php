<?php

declare(strict_types=1);

namespace App\Application\Quotes\Console;

use App\Application\Quotes\QuoteService;
use Illuminate\Console\Command;

/** REQ-QUO-002 — QUOTE_EXPIRED sweep: open quotes past their validity move to EXPIRED through QuoteMachine. */
final class ExpireQuotesCommand extends Command
{
    protected $signature = 'quotes:expire {--limit=500}';

    protected $description = 'Expire open quotes whose validity has elapsed (QUOTE_EXPIRED).';

    public function handle(QuoteService $quotes): int
    {
        $n = $quotes->expireDue((int) $this->option('limit'));
        $this->info("Expired {$n} quote(s).");

        return self::SUCCESS;
    }
}
