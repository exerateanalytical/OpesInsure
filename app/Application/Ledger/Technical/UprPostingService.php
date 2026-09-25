<?php

declare(strict_types=1);

namespace App\Application\Ledger\Technical;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Ledger\FinancialPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Batch 10-9 — REQ-ACC-004 optional period-end UPR movement posting. The movement is the computed UPR at period end
 * minus the UPR of the previous posting (same currency). An increase posts business event technical.upr.movement,
 * a decrease posts technical.upr.release, both through FinancialPostingService (Batch 10-6 maps them to GL
 * accounts; 10-8's PeriodGuard, inside LedgerService, refuses closed periods). One posting per tenant / period end /
 * currency — re-running returns the existing row.
 */
final class UprPostingService
{
    public function __construct(private TechnicalAccountingService $tech, private FinancialPostingService $posting, private AuditWriter $audit, private OutboxWriter $outbox) {}

    /** @return list<object> */
    public function post(string $tenantId, CarbonImmutable $periodEnd, string $actorId, string $correlation): array
    {
        // App\Domain\Ledger\JournalLine lives in Journal.php (not PSR-4 autoloadable on its own): load it for LedgerService.
        class_exists(\App\Domain\Ledger\Journal::class);
        $date = $periodEnd->toDateString();
        $out = [];
        foreach ($this->tech->uprTotals($tenantId, $periodEnd) as $currency => $upr) {
            $out[] = DB::transaction(function () use ($tenantId, $date, $currency, $upr, $actorId, $correlation) {
                $existing = DB::table('technical_upr_postings')->where(['tenant_id' => $tenantId, 'period_end' => $date, 'currency' => $currency])->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }
                $previous = DB::table('technical_upr_postings')->where(['tenant_id' => $tenantId, 'currency' => $currency])->where('period_end', '<', $date)->orderByDesc('period_end')->first();
                if (DB::table('technical_upr_postings')->where(['tenant_id' => $tenantId, 'currency' => $currency])->where('period_end', '>', $date)->exists()) {
                    throw ValidationException::withMessages(['period_end' => 'A later UPR posting exists for this currency.']);
                }
                $movement = $upr - (int) ($previous->upr_minor ?? 0);
                $id = (string) Str::uuid();
                $journal = $movement === 0 ? null : $this->posting->post($tenantId, $movement > 0 ? 'technical.upr.movement' : 'technical.upr.release', $id, abs($movement), $currency, $correlation);
                $now = now();
                DB::table('technical_upr_postings')->insert([
                    'id' => $id, 'tenant_id' => $tenantId, 'period_end' => $date, 'currency' => $currency, 'upr_minor' => $upr,
                    'previous_upr_minor' => (int) ($previous->upr_minor ?? 0), 'movement_minor' => $movement, 'journal_id' => $journal,
                    'posted_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit->record('technical.upr.posted', 'technical_upr_posting', $id, ['period_end' => $date, 'currency' => $currency, 'movement_minor' => $movement]);
                $this->outbox->record('technical.upr.posted', 'technical_upr_posting', $id, ['period_end' => $date, 'currency' => $currency, 'upr_minor' => $upr, 'movement_minor' => $movement, 'journal_id' => $journal]);

                return DB::table('technical_upr_postings')->where('id', $id)->first();
            });
        }

        return $out;
    }
}
