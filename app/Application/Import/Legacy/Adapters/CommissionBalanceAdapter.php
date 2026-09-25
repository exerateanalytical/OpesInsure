<?php

declare(strict_types=1);

namespace App\Application\Import\Legacy\Adapters;

use App\Application\Finance\Obligations\ObligationService;
use App\Application\Import\Legacy\LegacyAdapter;
use App\Application\Import\Legacy\LegacyContext;
use Illuminate\Support\Facades\DB;

/**
 * REQ-IMP-002 commission balances. A legacy commission still owed to an intermediary becomes one PAYABLE COMMISSION
 * obligation to that partner (resolved by licence number) through ObligationService; no accrual is re-computed
 * (the legacy rules are not the platform's). Optionally linked to a migrated policy. Opening balance posted by the
 * pipeline.
 */
final class CommissionBalanceAdapter implements LegacyAdapter
{
    public const SOURCE_TYPE = 'legacy_commission_balance';

    public function __construct(private readonly ObligationService $obligations) {}

    public function key(): string
    {
        return 'legacy.commission_balances';
    }

    public function label(): string
    {
        return 'Legacy commission balances';
    }

    public function recordType(): string
    {
        return 'legacy.commission_balance';
    }

    public function fields(): array
    {
        return ['legacy_id' => true, 'partner_licence_number' => true, 'amount' => true, 'currency' => true, 'due_at' => true, 'policy_legacy_id' => false];
    }

    public function validate(array $row, LegacyContext $ctx): array
    {
        $e = [];
        if (! $this->partner($row['partner_licence_number'])) {
            $e[] = ['rule' => 'PARTNER_KNOWN', 'field' => 'partner_licence_number', 'message' => "Unknown partner licence {$row['partner_licence_number']}."];
        }
        if ($row['policy_legacy_id'] !== null && ! $ctx->resolve('legacy.policy', $row['policy_legacy_id'])) {
            $e[] = ['rule' => 'POLICY_MIGRATED', 'field' => 'policy_legacy_id', 'message' => "Policy {$row['policy_legacy_id']} has not been migrated yet."];
        }
        $e = [...$e, ...PremiumAdapter::money($row['amount'], $row['currency'], 'amount')];
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
        $partnerId = $this->partner($row['partner_licence_number']);
        $amount = $this->sourceAmount($row);
        $o = $this->obligations->create([
            'tenant_id' => $ctx->tenantId, 'kind' => 'PAYABLE', 'type' => 'COMMISSION', 'source_type' => self::SOURCE_TYPE, 'source_id' => $partnerId,
            'source_reference' => mb_substr((string) $row['legacy_id'], 0, 120), 'policy_id' => $ctx->resolve('legacy.policy', $row['policy_legacy_id']),
            'creditor_type' => 'partner', 'creditor_id' => $partnerId, 'currency' => $row['currency'], 'amount_minor' => $amount,
            'due_at' => LegacyContext::date($row['due_at']), 'description' => 'Legacy commission balance',
            'metadata' => ['legacy_id' => $row['legacy_id'], 'batch_id' => $ctx->batchId],
        ], $ctx->actor->id);

        return ['id' => $o->id, 'opening_balance' => ['amount_minor' => $amount, 'currency' => $row['currency']]];
    }

    public function measure(string $id): ?int
    {
        $a = DB::table('financial_obligations')->where('id', $id)->value('amount_minor');

        return $a === null ? null : (int) $a;
    }

    private function partner(?string $licence): ?string
    {
        return $licence ? DB::table('partners')->where('licence_number', $licence)->value('id') : null;
    }
}
