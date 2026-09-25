<?php

declare(strict_types=1);

namespace App\Application\Accumulation;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Notifications\CustomerNotifier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-CAT-003 — large-loss notifier. The threshold is tenant configuration per currency (none seeded). A claim whose
 * loss (approved, else reserve, else estimate) reaches the threshold is notified once to the configured recipients
 * through the existing CustomerNotifier inbox/push channel, and a `claim.large_loss.detected` outbox event is written.
 */
final class LargeLossNotifier
{
    public function __construct(private readonly CustomerNotifier $notifier, private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public function configure(string $tenantId, string $currency, int $thresholdMinor, array $recipientUserIds): array
    {
        $existing = DB::table('large_loss_thresholds')->where('tenant_id', $tenantId)->where('currency', $currency)->first();
        $row = ['threshold_minor' => $thresholdMinor, 'recipient_user_ids' => json_encode(array_values($recipientUserIds)), 'status' => 'ACTIVE', 'updated_at' => now()];
        if ($existing) {
            DB::table('large_loss_thresholds')->where('id', $existing->id)->update($row);
            $id = $existing->id;
        } else {
            $id = (string) Str::uuid();
            DB::table('large_loss_thresholds')->insert($row + ['id' => $id, 'tenant_id' => $tenantId, 'currency' => $currency, 'created_at' => now()]);
        }
        $this->audit->record('claim.large_loss.threshold_set', 'large_loss_threshold', $id, ['currency' => $currency, 'threshold_minor' => $thresholdMinor]);

        return (array) DB::table('large_loss_thresholds')->find($id);
    }

    /** @return array{notified: bool, reason?: string, amount_minor?: int, threshold_minor?: int, recipients?: int} */
    public function check(string $tenantId, string $claimId): array
    {
        $claim = DB::table('claims')->where('tenant_id', $tenantId)->where('id', $claimId)->first() ?? abort(404, 'Claim not found.');
        $threshold = DB::table('large_loss_thresholds')->where('tenant_id', $tenantId)->where('currency', $claim->currency)->where('status', 'ACTIVE')->first();
        if (! $threshold) {
            return ['notified' => false, 'reason' => 'NOT_CONFIGURED'];
        }
        $amount = CatastropheEventService::claimLoss($claim);
        if ($amount < (int) $threshold->threshold_minor) {
            return ['notified' => false, 'reason' => 'BELOW_THRESHOLD', 'amount_minor' => $amount, 'threshold_minor' => (int) $threshold->threshold_minor];
        }
        if (DB::table('large_loss_notifications')->where('claim_id', $claimId)->exists()) {
            return ['notified' => false, 'reason' => 'ALREADY_NOTIFIED', 'amount_minor' => $amount, 'threshold_minor' => (int) $threshold->threshold_minor];
        }
        $recipients = 0;
        foreach (User::whereIn('id', json_decode((string) $threshold->recipient_user_ids, true) ?: [])->get() as $user) {
            $sent = $this->notifier->toUser($user, $tenantId, 'claim.large_loss', 'Large loss reported',
                "Claim {$claim->claim_number} has a loss of {$amount} {$claim->currency} (minor units), at or above the large-loss threshold.", 'WARNING', "/claims/{$claimId}");
            $recipients += $sent ? 1 : 0;
        }
        DB::table('large_loss_notifications')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'claim_id' => $claimId, 'amount_minor' => $amount,
            'threshold_minor' => (int) $threshold->threshold_minor, 'currency' => $claim->currency, 'recipients' => $recipients, 'created_at' => now()]);
        $payload = ['tenant_id' => $tenantId, 'amount_minor' => $amount, 'threshold_minor' => (int) $threshold->threshold_minor, 'currency' => $claim->currency,
            'catastrophe_event_id' => $claim->catastrophe_event_id ?? null];
        $this->audit->record('claim.large_loss.detected', 'claim', $claimId, $payload);
        $this->outbox->record('claim.large_loss.detected', 'claim', $claimId, $payload);

        return ['notified' => true, 'amount_minor' => $amount, 'threshold_minor' => (int) $threshold->threshold_minor, 'recipients' => $recipients];
    }
}
