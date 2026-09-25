<?php

declare(strict_types=1);

namespace App\Application\Integrations\Carriers\Console;

use App\Application\Integrations\Carriers\CarrierConnectorService;
use Illuminate\Console\Command;

/** REQ-API-007 — delivers due outbound carrier messages (retries with backoff, manual fallback). */
final class DispatchCarrierMessagesCommand extends Command
{
    protected $signature = 'carriers:dispatch-messages {--limit=100}';

    protected $description = 'Deliver due outbound carrier_exchange_messages through the carrier connectors';

    public function handle(CarrierConnectorService $connectors): int
    {
        $s = $connectors->dispatchDue((int) $this->option('limit'));
        $this->info("sent={$s['sent']} retry={$s['retry']} fallback={$s['fallback']} skipped={$s['skipped']}");

        return self::SUCCESS;
    }
}
