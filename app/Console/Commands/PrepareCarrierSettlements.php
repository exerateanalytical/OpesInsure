<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\FinancialDistribution\CarrierSettlementService;
use App\Models\Policy;
use App\Models\User;
use Carbon\CarbonImmutable;
use App\Application\Settlements\SettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Weekly carrier settlement drafts (A8): for every tenant/carrier/currency
 * with paid, issued policies in the period, prepares a DRAFT batch through
 * the governed Wave 6 CarrierSettlementService::prepare(). Finance still
 * approves (maker-checker: the preparer is the system actor, so any human
 * finance approver satisfies the separation constraint). Idempotent per
 * period via the batch idempotency key.
 *
 * D10 owner decision: POLICY-basis batches are retired. The weekly schedule was
 * removed (double-remittance risk with OBLIGATIONS batches, see
 * App\Application\Settlements\SettlementService). The command stays for manual
 * catch-up only and refuses any carrier group whose policies are already in a
 * live OBLIGATIONS batch. Existing POLICY batches stay readable for history.
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
        $refused = 0;
        foreach ($groups as $g) {
            if ($this->alreadyInObligationsBatch($g->tenant_id, $g->carrier_id, $g->currency, $start, $end)) {
                $refused++;
                $this->warn("Carrier {$g->carrier_id}: refused - policies already included in an OBLIGATIONS settlement batch (would double-remit).");

                continue;
            }
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

        $this->info("Prepared {$prepared} settlement batch(es) for {$start->toDateString()}..{$end->toDateString()}. Refused (OBLIGATIONS overlap): {$refused}.");

        return self::SUCCESS;
    }

    private function alreadyInObligationsBatch(string $tenantId, string $carrierId, string $currency, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return DB::table('settlement_items as i')->join('settlement_batches as sb', 'sb.id', '=', 'i.settlement_batch_id')
            ->join('policies as p', 'p.id', '=', 'i.policy_id')
            ->where('sb.calculation_basis', SettlementService::BASIS_OBLIGATIONS)->whereIn('sb.status', SettlementService::LIVE)
            ->where('p.tenant_id', $tenantId)->where('p.carrier_id', $carrierId)->where('p.currency', $currency)
            ->where('p.status', 'ACTIVE')->whereNotNull('p.payment_intent_id')
            ->whereBetween('p.issued_at', [$start->startOfDay(), $end->endOfDay()])->exists();
    }

    private function systemActor(): ?User
    {
        return User::where('status', 'ACTIVE')
            ->whereHas('memberships', fn ($q) => $q->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'FINANCE_ADMIN']))
            ->orderBy('created_at')->first();
    }
}
