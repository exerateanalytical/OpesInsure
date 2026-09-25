<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Finance\Obligations\ObligationService;
use App\Application\Finance\Obligations\PolicyPremiumObligations;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * REQ-OBL-001: one PREMIUM obligation for every issued policy that has none (issued before the money chain existed),
 * settled by the policy's bind payment when that payment SUCCEEDED. Idempotent.
 */
final class BackfillFinancialObligations extends Command
{
    protected $signature = 'finance:backfill-obligations {--dry-run : Count only} {--chunk=200}';

    protected $description = 'Create a single PREMIUM obligation (settled if paid) for issued policies without premium obligations';

    public function handle(ObligationService $obligations, PolicyPremiumObligations $premiums): int
    {
        $query = Policy::query()->whereNotNull('issued_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('financial_obligations')->whereColumn('financial_obligations.policy_id', 'policies.id')->whereIn('financial_obligations.type', ['PREMIUM', 'INSTALMENT']));

        $count = (clone $query)->count();
        if ($this->option('dry-run')) {
            $this->info("{$count} policies need a premium obligation backfill.");

            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;
        $query->orderBy('id')->chunkById((int) $this->option('chunk'), function ($policies) use ($obligations, $premiums, &$done, &$failed) {
            foreach ($policies as $policy) {
                try {
                    DB::transaction(function () use ($policy, $obligations, $premiums): void {
                        $total = (int) ($policy->premium_minor ?? $policy->terms_snapshot['total_minor'] ?? 0);
                        if ($total <= 0) {
                            return;
                        }
                        $obligations->create([
                            'tenant_id' => $policy->tenant_id, 'kind' => 'RECEIVABLE', 'type' => 'PREMIUM', 'currency' => $policy->currency ?? 'XAF',
                            'source_type' => PolicyPremiumObligations::POLICY_SOURCE, 'source_id' => $policy->id, 'policy_id' => $policy->id,
                            'debtor_type' => $policy->party_id ? 'party' : null, 'debtor_id' => $policy->party_id,
                            'creditor_type' => $policy->carrier_id ? 'carrier' : null, 'creditor_id' => $policy->carrier_id,
                            'amount_minor' => $total, 'due_at' => CarbonImmutable::parse($policy->coverage_starts_at ?? $policy->issued_at),
                            'description' => "Premium {$policy->policy_number}", 'metadata' => ['backfill' => true],
                        ]);
                        if ($policy->payment_intent_id && ($payment = PaymentIntentRecord::find($policy->payment_intent_id))) {
                            $premiums->settlePayment($payment);
                        }
                    });
                    $done++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("{$policy->id}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Backfilled {$done} policies; {$failed} failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
