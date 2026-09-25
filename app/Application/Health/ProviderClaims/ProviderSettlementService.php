<?php

declare(strict_types=1);

namespace App\Application\Health\ProviderClaims;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\Providers\ProviderRegistry;
use App\Interfaces\Http\Errors\ApiProblemException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-HLT-003 — provider settlement: PAYABLE provider claims are grouped per provider + currency into a batch; paying the
 * batch settles each claim's PAYABLE CLAIM obligation (ObligationService) and posts health.provider_claim.paid per claim.
 * Provider statements summarise one provider over a period.
 */
final class ProviderSettlementService
{
    public function __construct(
        private readonly ProviderClaimService $claims,
        private readonly ProviderRegistry $providers,
        private readonly ObligationService $obligations,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param list<string>|null $claimIds null = every unbatched PAYABLE claim of the provider in that currency */
    public function createBatch(string $tenantId, string $providerId, string $currency, ?array $claimIds, ?string $actorId): object
    {
        $this->providers->find($providerId);
        $currency = strtoupper($currency);

        return DB::transaction(function () use ($tenantId, $providerId, $currency, $claimIds, $actorId): object {
            $claims = DB::table('health_provider_claims')->where(['tenant_id' => $tenantId, 'provider_profile_id' => $providerId, 'currency' => $currency, 'status' => 'PAYABLE'])
                ->whereNull('settlement_batch_id')->when($claimIds !== null, fn ($q) => $q->whereIn('id', $claimIds))->lockForUpdate()->get();
            if ($claims->isEmpty() || ($claimIds !== null && $claims->count() !== count(array_unique($claimIds)))) {
                throw new ApiProblemException('NOTHING_TO_SETTLE', 422, 'Only unbatched PAYABLE claims of this provider and currency can be batched.');
            }
            $id = (string) Str::uuid();
            $total = (int) $claims->sum('insurer_share_minor');
            DB::table('health_provider_settlement_batches')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'batch_number' => 'HPS-'.now()->format('Ym').'-'.strtoupper(Str::random(8)), 'provider_profile_id' => $providerId,
                'currency' => $currency, 'status' => 'OPEN', 'total_minor' => $total, 'claim_count' => $claims->count(), 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('health_provider_claims')->whereIn('id', $claims->pluck('id'))->update(['settlement_batch_id' => $id, 'updated_at' => now()]);
            $this->audit->record('health.provider_settlement.created', 'health_provider_settlement_batch', $id, ['provider_id' => $providerId, 'total_minor' => $total, 'claims' => $claims->count()]);
            $this->outbox->record('health.provider_settlement.created', 'health_provider_settlement_batch', $id, ['batch_id' => $id, 'provider_id' => $providerId, 'total_minor' => $total, 'currency' => $currency]);

            return $this->batch($tenantId, $id);
        });
    }

    /** Pays an OPEN batch: settles every obligation, moves each claim to PAID and posts health.provider_claim.paid. */
    public function payBatch(string $tenantId, string $batchId, string $paymentReference, ?string $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $batchId, $paymentReference, $actorId): object {
            $b = DB::table('health_provider_settlement_batches')->where(['tenant_id' => $tenantId, 'id' => $batchId])->lockForUpdate()->first()
                ?? throw new ApiProblemException('SETTLEMENT_BATCH_NOT_FOUND', 404, 'Settlement batch not found.');
            if ($b->status !== 'OPEN') {
                throw new ApiProblemException('SETTLEMENT_BATCH_STATE', 409, "Settlement batch is {$b->status}.");
            }
            foreach (DB::table('health_provider_claims')->where('settlement_batch_id', $batchId)->lockForUpdate()->get() as $c) {
                $this->obligations->settle($c->financial_obligation_id, (int) $c->insurer_share_minor, 'hps:'.$batchId, $actorId);
                $this->claims->markPaid($c, $batchId, $actorId);
            }
            DB::table('health_provider_settlement_batches')->where('id', $batchId)->update(['status' => 'PAID', 'payment_reference' => $paymentReference, 'paid_at' => now(), 'paid_by' => $actorId, 'updated_at' => now()]);
            $this->audit->record('health.provider_settlement.paid', 'health_provider_settlement_batch', $batchId, ['payment_reference' => $paymentReference, 'total_minor' => (int) $b->total_minor]);
            $this->outbox->record('health.provider_settlement.paid', 'health_provider_settlement_batch', $batchId, ['batch_id' => $batchId, 'provider_id' => $b->provider_profile_id, 'total_minor' => (int) $b->total_minor, 'currency' => $b->currency]);

            return $this->batch($tenantId, $batchId);
        });
    }

    public function batch(string $tenantId, string $id): object
    {
        $b = DB::table('health_provider_settlement_batches')->where(['tenant_id' => $tenantId, 'id' => $id])->first()
            ?? throw new ApiProblemException('SETTLEMENT_BATCH_NOT_FOUND', 404, 'Settlement batch not found.');
        $b->claims = DB::table('health_provider_claims')->where('settlement_batch_id', $id)->orderBy('claim_number')
            ->get(['id', 'claim_number', 'invoice_reference', 'status', 'insurer_share_minor', 'financial_obligation_id'])->all();

        return $b;
    }

    /** Provider statement for a period (by service date): per-status totals, each claim, and the batches paid in the period. */
    public function statement(string $tenantId, string $providerId, string $from, string $to): array
    {
        $p = $this->providers->find($providerId);
        [$f, $t] = [CarbonImmutable::parse($from)->toDateString(), CarbonImmutable::parse($to)->toDateString()];
        $claims = DB::table('health_provider_claims')->where(['tenant_id' => $tenantId, 'provider_profile_id' => $providerId])
            ->whereBetween('service_date', [$f, $t])->where('status', '<>', 'DRAFT')->orderBy('service_date')->get();
        $sum = fn ($rows) => ['count' => $rows->count(), 'billed_minor' => (int) $rows->sum('billed_minor'), 'allowed_minor' => (int) $rows->sum('allowed_minor'),
            'insurer_share_minor' => (int) $rows->sum('insurer_share_minor'), 'rejected_minor' => (int) $rows->sum('rejected_minor')];

        return [
            'provider' => ['id' => $p->id, 'name' => $p->name, 'party_id' => $p->party_id], 'period' => ['from' => $f, 'to' => $t],
            'totals' => $sum($claims) + [
                'paid_minor' => (int) $claims->where('status', 'PAID')->sum('insurer_share_minor'),
                'outstanding_minor' => (int) $claims->where('status', 'PAYABLE')->sum('insurer_share_minor'),
            ],
            'by_status' => $claims->groupBy('status')->map($sum)->all(),
            'claims' => $claims->map(fn ($c) => [
                'id' => $c->id, 'claim_number' => $c->claim_number, 'invoice_reference' => $c->invoice_reference, 'service_date' => $c->service_date, 'status' => $c->status,
                'currency' => $c->currency, 'billed_minor' => (int) $c->billed_minor, 'insurer_share_minor' => (int) $c->insurer_share_minor, 'rejected_minor' => (int) $c->rejected_minor,
                'settlement_batch_id' => $c->settlement_batch_id,
            ])->values()->all(),
            'batches' => DB::table('health_provider_settlement_batches')->where(['tenant_id' => $tenantId, 'provider_profile_id' => $providerId, 'status' => 'PAID'])
                ->whereBetween('paid_at', [$f.' 00:00:00', $t.' 23:59:59'])->orderBy('paid_at')->get(['id', 'batch_number', 'total_minor', 'currency', 'payment_reference', 'paid_at'])->all(),
        ];
    }
}
