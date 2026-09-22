<?php

declare(strict_types=1);

use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\Party;
use App\Models\PartyContact;
use Illuminate\Support\Str;

if (! function_exists('makeNotificationTestParty')) {
    function makeNotificationTestParty(string $contactType, string $destination): Party
    {
        $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Notification Test Party', 'status' => 'ACTIVE']);

        PartyContact::create([
            'party_id' => $party->id,
            'type' => $contactType,
            'normalized_value' => $destination,
            'is_primary' => true,
        ]);

        return $party;
    }

    function makeNotificationTestTemplate(string $channel, string $body = 'Hello {{name}}, your code is {{code}}.', ?string $subject = 'OpesInsure'): NotificationTemplate
    {
        return NotificationTemplate::create([
            'code' => 'test-'.Str::random(8),
            'locale' => 'en',
            'purpose' => 'TRANSACTIONAL',
            'channel' => $channel,
            'subject' => $subject,
            'body' => $body,
            'required_variables' => ['name', 'code'],
            'version' => 1,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * A QUEUED notification_deliveries row exactly as NotificationController::queue()
     * would have created it — destination_hash computed the same way, so
     * NotificationDispatchService's consistency check passes.
     */
    function makeNotificationTestDelivery(string $channel, string $destination, array $overrides = []): NotificationDelivery
    {
        $party = makeNotificationTestParty($channel === 'EMAIL' ? 'EMAIL' : 'PHONE', $destination);
        $template = makeNotificationTestTemplate($channel);

        return NotificationDelivery::create(array_merge([
            'party_id' => $party->id,
            'template_id' => $template->id,
            'channel' => $channel,
            'destination_hash' => hash('sha256', mb_strtolower(trim($destination))),
            'status' => 'QUEUED',
            'attempts' => 0,
            'max_attempts' => 5,
            'payload' => ['name' => 'Amina', 'code' => '123456'],
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides));
    }
}
