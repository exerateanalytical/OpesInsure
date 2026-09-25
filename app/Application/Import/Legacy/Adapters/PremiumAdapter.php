<?php

declare(strict_types=1);

namespace App\Application\Import\Legacy\Adapters;

use App\Application\Finance\Obligations\ObligationService;
use App\Application\Import\Legacy\LegacyAdapter;
use App\Application\Import\Legacy\LegacyContext;
use Illuminate\Support\Facades\DB;

/**
 * REQ-IMP-002 outstanding premiums. Each legacy balance becomes one RECEIVABLE PREMIUM obligation through
 * ObligationService (idempotent on source_type legacy_premium + policy + legacy id) and is carried to the ledger as
 * an opening balance by the pipeline.
 */
final class PremiumAdapter implements LegacyAdapter
{
    public const SOURCE_TYPE = 'legacy_premium';

    public function __construct(private readonly ObligationService $obligations) {}

    public function key(): string
    {
        return 'legacy.premiums';
    }

    public function label(): string
    {
        return 'Legacy outstanding premiums';
    }

    public function recordType(): string
    {
        return 'legacy.premium';
    }

    public function fields(): array
    {
        return ['legacy_id' => true, 'policy_legacy_id' => true, 'amount' => true, 'currency' => true, 'due_at' => true, 'description' => false];
    }

    public function validate(array $row, LegacyContext $ctx): array
    {
        $e = [];
        $policyId = $ctx->resolve('legacy.policy', $row['policy_legacy_id']);
        if (! $policyId) {
            $e[] = ['rule' => 'POLICY_MIGRATED', 'field' => 'policy_legacy_id', 'message' => "Policy {$row['policy_legacy_id']} has not been migrated yet."];
        } elseif (($cur = DB::table('policies')->where('id', $policyId)->value('currency')) !== $row['currency']) {
            $e[] = ['rule' => 'CURRENCY_MATCHES_POLICY', 'field' => 'currency', 'message' => "Currency differs from the policy currency {$cur}."];
        }
        $e = [...$e, ...self::money($row['amount'], $row['currency'], 'amount')];
        if (! LegacyContext::date($row['due_at'])) {
            $e[] = ['rule' => 'DATE', 'field' => 'due_at', 'message' => 'Due date is not a valid date.'];
        }

        return $e;
    }

    public function sourceAmount(array $row): int
    {
        return (int) LegacyContext::minor($row['amount'], (string) $row['currency']);
    }

    public function migrate(array $row, LegacyContext $ctx): array
    {
        $policyId = $ctx->resolve('legacy.policy', $row['policy_legacy_id']);
        $policy = DB::table('policies')->where('id', $policyId)->first(['party_id']);
        $amount = $this->sourceAmount($row);
        $o = $this->obligations->create([
            'tenant_id' => $ctx->tenantId, 'kind' => 'RECEIVABLE', 'type' => 'PREMIUM', 'source_type' => self::SOURCE_TYPE, 'source_id' => $policyId,
            'source_reference' => mb_substr((string) $row['legacy_id'], 0, 120), 'policy_id' => $policyId, 'debtor_type' => 'party', 'debtor_id' => $policy->party_id,
            'currency' => $row['currency'], 'amount_minor' => $amount, 'due_at' => LegacyContext::date($row['due_at']),
            'description' => $row['description'] ?? 'Legacy outstanding premium', 'metadata' => ['legacy_id' => $row['legacy_id'], 'batch_id' => $ctx->batchId],
        ], $ctx->actor->id);

        return ['id' => $o->id, 'opening_balance' => ['amount_minor' => $amount, 'currency' => $row['currency']]];
    }

    public function measure(string $id): ?int
    {
        $a = DB::table('financial_obligations')->where('id', $id)->value('amount_minor');

        return $a === null ? null : (int) $a;
    }

    /** Positive amount in the currency precision (shared by the balance adapters). */
    public static function money(?string $amount, ?string $currency, string $field): array
    {
        if (! preg_match('/^[A-Z]{3}$/', (string) $currency)) {
            return [['rule' => 'CURRENCY', 'field' => 'currency', 'message' => 'Currency must be an ISO 4217 code.']];
        }
        $m = LegacyContext::minor($amount, $currency);

        return $m === null || $m <= 0 ? [['rule' => 'AMOUNT', 'field' => $field, 'message' => 'Amount must be positive and in the currency precision.']] : [];
    }
}
