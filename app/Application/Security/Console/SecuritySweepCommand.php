<?php

declare(strict_types=1);

namespace App\Application\Security\Console;

use App\Application\Security\Findings\SecurityFindingService;
use App\Application\Security\PrivilegedAccessService;
use Illuminate\Console\Command;

final class SecuritySweepCommand extends Command
{
    protected $signature = 'security:sweep';

    protected $description = 'Expire privileged-access grants past their window and reopen expired security-finding risk acceptances.';

    public function handle(PrivilegedAccessService $access, SecurityFindingService $findings): int
    {
        $this->info('Expired grants: '.$access->expireDue().'; reopened findings: '.$findings->reopenExpiredAcceptances());

        return self::SUCCESS;
    }
}
