<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\FinancialDistribution\CarrierSettlementService;
use App\Models\Policy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Weekly carrier settlement drafts (A8): for every tenant/carrier/currency
 * with paid, issued policies in the period, prepares a DRAFT batch through
 * the governed Wave 6 CarrierSettlementService::prepare(). Finance still
 * approves (maker-checker: the preparer is the system actor, so any human
 * finance approver satisfies the separation constraint). Idempotent per
 * period via the batch idempotency key.
 */
final class PrepareCarrierSettlements extends Command
{
    protected $signature = 'settlements:prepare {--from= : Period start (Y-m-d), default: last Monday-Sunday week} {--to= : Period end (Y-m-d)}';

    protected $description = 'Prepare DRAFT carrier settlement batches for the previous period.';

    public function handle(CarrierSettlementService $settlements): int
    {
        $start = $this->option('from') ? CarbonImmutable::parse($this->option('from')) : CarbonImmutable::now()->subWeek()->startOfWeek();
        $end = $this->option('to') ? CarbonImmutable::parse($this->option('to')) : $start->endOfWeek();
        $actor = $this->systemActor();
        if (! $actor) {
            $this->warn('No platform/finance administrator exists to act as preparer; nothing prepared.');

            return self::SUCCESS;
        }

        $groups = Policy::query()->where('status', 'ACTIVE')->whereNotNull('payment_intent_id')
            ->whereBetween('issued_at', [$start->startOfDay(), $end->endOfDay()])
            ->select('tenant_id', 'carrier_id', 'currency')->distinct()->get();

        $prepared = 0;
        foreach ($groups as $g) {
            try {
                $settlements->prepare([
                    'tenant_id' => $g->tenant_id, 'carrier_id' => $g->carrier_id, 'currency' => $g->currency,
                    'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                    'idempotency_key' => 'auto:'.hash('sha256', implode('|', [$g->tenant_id, $g->carrier_id, $g->currency, $start->toDateString(), $end->toDateString()])),
                ], $actor);
                $prepared++;
            } catch (Throwable $e) {
                report($e);
                $this->error("Carrier {$g->carrier_id}: {$e->getMessage()}");
            }
        }

        $this->info("Prepared {$prepared} settlement batch(es) for {$start->toDateString()}..{$end->toDateString()}.");

        return self::SUCCESS;
    }

    private function systemActor(): ?User
    {
        return User::where('status', 'ACTIVE')
            ->whereHas('memberships', fn ($q) => $q->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'FINANCE_ADMIN']))
            ->orderBy('created_at')->first();
    }
}
