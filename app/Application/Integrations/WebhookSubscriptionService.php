<?php

declare(strict_types=1);

namespace App\Application\Integrations;

use App\Models\CanonicalEventSchema;
use App\Models\IntegrationClient;
use App\Models\IntegrationWebhookSubscription;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * DEV-009 webhook configuration: one place that subscribes a partner connection to a canonical event (used by
 * POST integrations/clients/{client}/webhooks and the admin webhook screen). The signing secret is returned exactly
 * once; only its hash and an encrypted copy (for signing deliveries) are stored.
 */
final class WebhookSubscriptionService
{
    /** @return array{subscription: IntegrationWebhookSubscription, signing_secret: string} */
    public function subscribe(IntegrationClient $client, string $eventName, string $endpoint): array
    {
        if (! CanonicalEventSchema::where('event_name', $eventName)->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['event_name' => "[{$eventName}] is not a registered, active canonical event. See the canonical event schema catalogue."]);
        }
        if (! str_starts_with(strtolower($endpoint), 'https://') || filter_var($endpoint, FILTER_VALIDATE_URL) === false || strlen($endpoint) > 500) {
            throw ValidationException::withMessages(['endpoint' => 'The endpoint must be an https URL of at most 500 characters.']);
        }
        abort_unless($client->status === 'ACTIVE', 409, 'Only an ACTIVE connection may subscribe to webhooks.');

        $secret = Str::random(64);
        $subscription = IntegrationWebhookSubscription::create([
            'integration_client_id' => $client->id,
            'event_name' => $eventName,
            'endpoint_encrypted' => Crypt::encryptString($endpoint),
            'signing_secret_hash' => bcrypt($secret),
            'signing_secret_encrypted' => Crypt::encryptString($secret),
            'status' => 'ACTIVE',
        ]);

        return ['subscription' => $subscription, 'signing_secret' => $secret];
    }
}
