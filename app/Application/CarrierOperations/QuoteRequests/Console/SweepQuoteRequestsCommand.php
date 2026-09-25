<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\QuoteRequests\Console;

use App\Application\CarrierOperations\QuoteRequests\QuoteRequestService;
use Illuminate\Console\Command;

/** REQ-QUO-006 — closes open carrier quote requests whose quote expired or was cancelled/accepted elsewhere. */
final class SweepQuoteRequestsCommand extends Command
{
    protected $signature = 'carrier-quote-requests:sweep';

    protected $description = 'Close manual quotation requests whose quote is no longer open (REQ-QUO-006).';

    public function handle(QuoteRequestService $service): int
    {
        $this->info('Closed '.$service->sweep().' carrier quote request(s).');

        return self::SUCCESS;
    }
}
