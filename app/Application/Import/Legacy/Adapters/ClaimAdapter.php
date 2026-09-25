<?php

declare(strict_types=1);

namespace App\Application\Import\Legacy\Adapters;

use App\Application\Import\Legacy\LegacyAdapter;
use App\Application\Import\Legacy\LegacyContext;
use App\Domain\Claims\ClaimMachine;
use App\Models\Claim;
use App\Models\ClaimReserveChange;
use Illuminate\Support\Facades\DB;

/**
 * REQ-IMP-002 open claims + reserves. The claim keeps its legacy number and status; each reserve head carried by
 * the legacy system becomes one INITIAL claim_reserve_changes row, APPROVED by the checker who approved the batch
 * (requested_by = the batch maker, so the table's maker-checker constraint holds) with reason LEGACY_MIGRATION.
 * The reserves are not re-posted on claim.reserve.changed: the total reserve is the claim's opening balance and the
 * pipeline posts it on migration.opening_balance.
 */
final class ClaimAdapter implements LegacyAdapter
{
    public const ORIGIN = 'LEGACY_MIGRATION';

    public const REASON = 'LEGACY_MIGRATION';

    public function key(): string
    {
        return 'legacy.claims';
    }

    public function label(): string
    {
        return 'Legacy claims and reserves';
    }

    public function recordType(): string
    {
        return 'legacy.claim';
    }

    public function fields(): array
    {
        return ['legacy_id' => true, 'policy_legacy_id' => true, 'claim_number' => true, 'status' => true, 'loss_occurred_at' => true, 'currency' => true,
            'reserve_indemnity' => false, 'reserve_expense' => false, 'description' => false];
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
        if (! in_array(strtoupper((string) $row['status']), self::statuses(), true)) {
            $e[] = ['rule' => 'CLAIM_STATUS', 'field' => 'status', 'message' => 'Unknown claim status '.$row['status'].'.'];
        }
        if (! LegacyContext::date($row['loss_occurred_at'])) {
            $e[] = ['rule' => 'DATE', 'field' => 'loss_occurred_at', 'message' => 'Loss date is not a valid date.'];
        }
        if (Claim::where('claim_number', $row['claim_number'])->exists()) {
            $e[] = ['rule' => 'CLAIM_NUMBER_UNIQUE', 'field' => 'claim_number', 'message' => "Claim number {$row['claim_number']} already exists."];
        }
        foreach (['reserve_indemnity', 'reserve_expense'] as $f) {
            if ($row[$f] !== null && (($m = LegacyContext::minor($row[$f], (string) $row['currency'])) === null || $m < 0)) {
                $e[] = ['rule' => 'AMOUNT', 'field' => $f, 'message' => 'Reserve must be a non-negative amount in the currency precision.'];
            }
        }

        return $e;
    }

    public function sourceAmount(array $row): int
    {
        return array_sum($this->heads($row));
    }

    public function migrate(array $row, LegacyContext $ctx): array
    {
        $policyId = $ctx->resolve('legacy.policy', $row['policy_legacy_id']);
        $policy = DB::table('policies')->where('id', $policyId)->first(['party_id']);
        $heads = $this->heads($row);
        $total = array_sum($heads);
        $status = strtoupper((string) $row['status']);
        $loss = LegacyContext::date($row['loss_occurred_at']);
        $claim = tap((new Claim)->forceFill([
            'tenant_id' => $ctx->tenantId, 'policy_id' => $policyId, 'claimant_party_id' => $policy->party_id, 'claim_number' => $row['claim_number'],
            'status' => $status, 'loss_occurred_at' => $loss, 'currency' => $row['currency'], 'submitted_at' => $loss,
            'loss_details' => array_filter(['description' => $row['description'], 'legacy' => ['legacy_id' => $row['legacy_id'], 'source' => $ctx->source->name, 'batch_id' => $ctx->batchId]]),
            'closed_at' => $status === 'CLOSED' ? now() : null, 'current_reserve_minor' => $total, 'data_origin' => self::ORIGIN,
        ]))->save();
        $maker = $ctx->makerId ?? $ctx->actor->id;
        $seq = 0;
        foreach ($heads as $head => $amount) {
            if ($amount <= 0) {
                continue;
            }
            ClaimReserveChange::create([
                'claim_id' => $claim->id, 'previous_amount_minor' => 0, 'requested_amount_minor' => $amount, 'movement_minor' => $amount, 'currency' => $row['currency'],
                'status' => 'APPROVED', 'reason_code' => self::REASON, 'reserve_head' => $head, 'reserve_stage' => 'INITIAL', 'approval_seq' => ++$seq,
                'notes' => 'Opening reserve migrated from '.$ctx->source->name, 'requested_by' => $maker,
                'approved_by' => $ctx->checkerId !== $maker ? $ctx->checkerId : null, 'approved_at' => now(),
            ]);
        }

        return ['id' => $claim->id, 'notes' => ['reserve_heads' => $seq], 'opening_balance' => ['amount_minor' => $total, 'currency' => $row['currency']]];
    }

    public function measure(string $id): ?int
    {
        $r = DB::table('claims')->where('id', $id)->value('current_reserve_minor');

        return $r === null ? null : (int) $r;
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_values(array_diff(array_keys(ClaimMachine::BLUEPRINT), ['DRAFT']));
    }

    /** @return array{INDEMNITY: int, EXPENSE: int} */
    private function heads(array $row): array
    {
        return ['INDEMNITY' => (int) LegacyContext::minor($row['reserve_indemnity'] ?? null, (string) $row['currency']),
            'EXPENSE' => (int) LegacyContext::minor($row['reserve_expense'] ?? null, (string) $row['currency'])];
    }
}
