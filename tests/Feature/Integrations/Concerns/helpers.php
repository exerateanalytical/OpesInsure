<?php

declare(strict_types=1);

use App\Application\Integrations\IntegrationClientLifecycleService;
use App\Models\{IntegrationClient,IntegrationWebhookSubscription,User};
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

if (! function_exists('registerTestIntegrationClient')) {
    function registerTestIntegrationClient(User $actor, array $overrides = []): IntegrationClient
    {
        $service = app(IntegrationClientLifecycleService::class);

        return $service->register([
            'name' => 'Test Connector',
            'scopes' => ['quotes.read'],
            'rate_limit_per_minute' => 60,
            ...$overrides,
        ], $actor)['integration_client'];
    }

    function activateTestIntegrationClient(User $actor, array $overrides = []): IntegrationClient
    {
        $service = app(IntegrationClientLifecycleService::class);
        $client = registerTestIntegrationClient($actor, $overrides);

        foreach (['TECHNICAL_REVIEW', 'SANDBOX_ENABLED', 'CERTIFICATION', 'PRODUCTION_APPROVED', 'ACTIVE'] as $reason) {
            $client = $service->advance($client, $reason, null, $actor);
        }

        return $client;
    }

    /** @return array{0: IntegrationWebhookSubscription, 1: string} [subscription, plaintext signing secret] */
    function makeTestWebhookSubscription(User $actor, string $endpoint = 'https://partner.example.test/webhooks', string $eventName = 'policy.issued'): array
    {
        $client = activateTestIntegrationClient($actor);
        $secret = Str::random(64);
        $subscription = IntegrationWebhookSubscription::create([
            'integration_client_id' => $client->id,
            'event_name' => $eventName,
            'endpoint_encrypted' => Crypt::encryptString($endpoint),
            'signing_secret_hash' => bcrypt($secret),
            'signing_secret_encrypted' => Crypt::encryptString($secret),
            'status' => 'ACTIVE',
        ]);

        return [$subscription, $secret];
    }
}
