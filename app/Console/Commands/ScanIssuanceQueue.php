<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Policies\IssuanceQueue\IssuanceQueueService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * REQ-POL-004 — hourly paid-not-issued sweep: runs IssuanceQueueService::scan() for every tenant that has
 * SUCCEEDED + reconciled payments linked to a proposal, with that tenant set as the tenant context.
 */
final class ScanIssuanceQueue extends Command
{
    protected $signature = 'policies:scan-issuance-queue {--grace=30 : minutes after reconciliation before a payment counts} {--review-hours=48 : hours a request may stay in carrier review}';

    protected $description = 'Record paid-not-issued issuance exceptions for every tenant.';

    public function handle(IssuanceQueueService $queue, TenantContext $context): int
    {
        $tenantIds = DB::table('payment_intents')->where('status', 'SUCCEEDED')->whereNotNull('reconciled_at')->whereNotNull('proposal_id')
            ->distinct()->pluck('tenant_id');
        $total = 0;
        foreach ($tenantIds as $tenantId) {
            $context->set((string) $tenantId);
            try {
                $total += count($queue->scan((string) $tenantId, (int) $this->option('grace'), (int) $this->option('review-hours')));
            } finally {
                $context->clear();
            }
        }
        $this->info("Tenants scanned: {$tenantIds->count()}. Exceptions recorded: {$total}.");

        return self::SUCCESS;
    }
}
