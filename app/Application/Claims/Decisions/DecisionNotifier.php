<?php

declare(strict_types=1);

namespace App\Application\Claims\Decisions;

use App\Application\Events\OutboxWriter;
use App\Models\Claim;
use App\Models\ClaimDecision;
use App\Models\NotificationTemplate;
use App\Models\PartyContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-CLM-012 customer notification of a claim decision with its reasons. Queues a notification_deliveries row
 * (template claim.decision.notified) to the claimant's primary contact; actual sending stays with
 * notifications:dispatch-pending. No contact → recorded NO_CONTACT (the decision still stands).
 */
final class DecisionNotifier
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    public function notify(Claim $claim, ClaimDecision $decision): void
    {
        $labels = DB::table('claim_decision_reason_codes')->whereIn('code', $decision->reason_codes ?? [$decision->reason_code])
            ->get()->map(fn ($r) => $r->customer_text ?? $r->label)->all();
        $payload = [
            'claim_number' => $claim->claim_number, 'decision' => $decision->decision, 'kind' => $decision->kind,
            'amount' => (string) $decision->approved_amount_minor, 'currency' => $decision->currency,
            'reasons' => implode('; ', $labels), 'reason_codes' => $decision->reason_codes, 'heads' => $decision->heads,
        ];

        $deliveryId = null;
        $contact = $claim->claimant_party_id ? PartyContact::where('party_id', $claim->claimant_party_id)->where('is_primary', true)
            ->whereIn('type', ['PHONE', 'EMAIL'])->orderByRaw("CASE WHEN type = 'PHONE' THEN 0 ELSE 1 END")->first() : null;
        $channel = $contact?->type === 'EMAIL' ? 'EMAIL' : 'SMS';
        $template = $contact ? NotificationTemplate::where(['code' => 'claim.decision.notified', 'channel' => $channel, 'locale' => 'en', 'status' => 'ACTIVE'])->first() : null;
        if ($contact && $template) {
            $deliveryId = (string) Str::uuid();
            DB::table('notification_deliveries')->insert([
                'id' => $deliveryId, 'tenant_id' => $claim->tenant_id, 'party_id' => $claim->claimant_party_id, 'template_id' => $template->id,
                'channel' => $channel, 'destination_hash' => hash('sha256', mb_strtolower(trim($contact->normalized_value))),
                'status' => 'QUEUED', 'attempts' => 0, 'max_attempts' => 5, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'idempotency_key' => 'claim-decision:'.$decision->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $decision->update(['notification_delivery_id' => $deliveryId, 'notification_status' => $deliveryId ? 'QUEUED' : 'NO_CONTACT', 'notified_at' => $deliveryId ? now() : null]);
        $this->outbox->record('claim.decision.notified', 'claim', $claim->id, ['decision_id' => $decision->id, 'notification_delivery_id' => $deliveryId, 'reason_codes' => $decision->reason_codes]);
    }
}
