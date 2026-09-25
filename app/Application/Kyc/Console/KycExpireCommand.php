<?php

declare(strict_types=1);

namespace App\Application\Kyc\Console;

use App\Application\Kyc\KycService;
use Illuminate\Console\Command;

/** REQ-KYC-003: marks approved KYC past expires_at as EXPIRED (scheduled daily). */
final class KycExpireCommand extends Command
{
    protected $signature = 'kyc:expire';

    protected $description = 'Expire approved KYC submissions whose expiry date has passed.';

    public function handle(KycService $kyc): int
    {
        $this->info('Expired: '.$kyc->expireDue());

        return self::SUCCESS;
    }
}
