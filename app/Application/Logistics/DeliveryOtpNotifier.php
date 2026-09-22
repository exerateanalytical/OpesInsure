<?php

declare(strict_types=1);

namespace App\Application\Logistics;

use App\Models\NotificationTemplate;
use App\Models\PartyContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FulfilmentController::store() used to return the plaintext delivery OTP
 * directly in the API response — defeating the point of an OTP "challenge"
 * meant to be presented to the customer out-of-band. This queues it through
 * the real SMS pipeline instead (NotificationDispatchService + the
 * fulfilment.delivery_otp template seeded by NotificationTemplateSeeder);
 * actual delivery still goes through notifications:dispatch-pending like
 * every other notification, so it keeps the same retry/dead-letter handling.
 */
final class DeliveryOtpNotifier
{
    public function notify(string $partyId, string $otp): void
    {
        $contact = PartyContact::where('party_id', $partyId)->where('type', 'PHONE')->where('is_primary', true)->first();

        if (! $contact) {
            return; // nothing to notify — FulfilmentController still proceeds; the OTP can be resent via a future admin action
        }

        $template = NotificationTemplate::where(['code' => 'fulfilment.delivery_otp', 'channel' => 'SMS', 'locale' => 'en', 'status' => 'ACTIVE'])
            ->whereNull('tenant_id')
            ->first();

        if (! $template) {
            return; // template not seeded — nothing to queue against
        }

        DB::table('notification_deliveries')->insert([
            'id' => (string) Str::uuid(),
            'party_id' => $partyId,
            'template_id' => $template->id,
            'channel' => 'SMS',
            'destination_hash' => hash('sha256', mb_strtolower(trim($contact->normalized_value))),
            'status' => 'QUEUED',
            'attempts' => 0,
            'max_attempts' => 5,
            'payload' => json_encode(['otp' => $otp]),
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
