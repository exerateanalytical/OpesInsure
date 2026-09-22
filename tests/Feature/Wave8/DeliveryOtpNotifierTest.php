<?php

declare(strict_types=1);

use App\Application\Logistics\DeliveryOtpNotifier;
use App\Models\NotificationTemplate;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('queues an SMS against the seeded fulfilment.delivery_otp template with the correct destination_hash and payload', function () {
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Otp Party', 'status' => 'ACTIVE']);
    $party->contacts()->create(['type' => 'PHONE', 'normalized_value' => '+237670000000', 'is_primary' => true]);

    $template = NotificationTemplate::create(['code' => 'fulfilment.delivery_otp', 'locale' => 'en', 'purpose' => 'TRANSACTIONAL', 'channel' => 'SMS', 'body' => 'Code: {{otp}}', 'required_variables' => ['otp'], 'version' => 1, 'status' => 'ACTIVE']);

    (new DeliveryOtpNotifier)->notify($party->id, '654321');

    $delivery = DB::table('notification_deliveries')->where('party_id', $party->id)->first();

    expect($delivery)->not->toBeNull();
    expect($delivery->channel)->toBe('SMS');
    expect($delivery->template_id)->toBe($template->id);
    expect($delivery->status)->toBe('QUEUED');
    expect($delivery->destination_hash)->toBe(hash('sha256', '+237670000000'));
    expect(json_decode($delivery->payload, true))->toBe(['otp' => '654321']);
});

it('does nothing when the party has no primary phone contact', function () {
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'No Phone', 'status' => 'ACTIVE']);
    NotificationTemplate::create(['code' => 'fulfilment.delivery_otp', 'locale' => 'en', 'purpose' => 'TRANSACTIONAL', 'channel' => 'SMS', 'body' => 'Code: {{otp}}', 'required_variables' => ['otp'], 'version' => 1, 'status' => 'ACTIVE']);

    (new DeliveryOtpNotifier)->notify($party->id, '654321');

    expect(DB::table('notification_deliveries')->where('party_id', $party->id)->exists())->toBeFalse();
});

it('does nothing when the fulfilment.delivery_otp template is not seeded', function () {
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'No Template', 'status' => 'ACTIVE']);
    $party->contacts()->create(['type' => 'PHONE', 'normalized_value' => '+237670000000', 'is_primary' => true]);

    (new DeliveryOtpNotifier)->notify($party->id, '654321');

    expect(DB::table('notification_deliveries')->where('party_id', $party->id)->exists())->toBeFalse();
});
