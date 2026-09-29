<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Integrations;

use App\Application\Integrations\{IntegrationClientLifecycleService,IntegrationHealthService,WebhookDeliveryService};
use App\Interfaces\Http\Controllers\Concerns\AuthorizesSensitiveActions;
use App\Models\{CanonicalEventSchema,IntegrationClient,IntegrationDeliveryAttempt,IntegrationWebhookSubscription};
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;

final class IntegrationController
{
    use AuthorizesSensitiveActions;

    public function createClient(Request $r, IntegrationClientLifecycleService $service): JsonResponse
    {
        $d = $r->validate([
            'partner_id' => 'nullable|uuid|exists:partners,id',
            'name' => 'required|string|max:160',
            'scopes' => 'required|array|min:1',
            'scopes.*' => ['string', \Illuminate\Validation\Rule::in(\App\Application\Integrations\Developer\OAuthScopeCatalogue::scopes())],
            'allowed_ips' => 'sometimes|array',
            'allowed_ips.*' => 'ip',
            'rate_limit_per_minute' => 'required|integer|min:1|max:1000',
            'environment' => 'sometimes|in:sandbox,production',
        ]);

        $result = $this->auditedCall(fn () => $service->register($d, $r->user()), 'integration.client.created', 'integration_client', null);

        return response()->json(['data' => ['id' => $result['integration_client']->id, 'client_id' => $result['client_id'], 'client_secret' => $result['client_secret'], 'status' => $result['integration_client']->status]], 201);
    }

    public function advance(Request $r, IntegrationClient $client, IntegrationClientLifecycleService $service): JsonResponse
    {
        $d = $r->validate(['reason_code' => 'required|string|max:64', 'notes' => 'nullable|string|max:2000']);

        return response()->json($this->auditedCall(fn () => $service->advance($client, $d['reason_code'], $d['notes'] ?? null, $r->user()), 'integration.client.advanced', 'integration_client', $client->id));
    }

    public function suspend(Request $r, IntegrationClient $client, IntegrationClientLifecycleService $service): JsonResponse
    {
        $d = $r->validate(['reason_code' => 'required|string|max:64', 'notes' => 'required|string|max:2000']);

        return response()->json($this->auditedCall(fn () => $service->suspend($client, $d['reason_code'], $d['notes'], $r->user()), 'integration.client.suspended', 'integration_client', $client->id));
    }

    public function reinstate(Request $r, IntegrationClient $client, IntegrationClientLifecycleService $service): JsonResponse
    {
        $d = $r->validate(['notes' => 'required|string|max:2000']);

        return response()->json($this->auditedCall(fn () => $service->reinstate($client, $d['notes'], $r->user()), 'integration.client.reinstated', 'integration_client', $client->id));
    }

    public function revoke(Request $r, IntegrationClient $client, IntegrationClientLifecycleService $service): JsonResponse
    {
        $d = $r->validate(['reason_code' => 'required|string|max:64', 'notes' => 'required|string|max:2000']);

        return response()->json($this->auditedCall(fn () => $service->revoke($client, $d['reason_code'], $d['notes'], $r->user()), 'integration.client.revoked', 'integration_client', $client->id));
    }

    public function subscribe(Request $r, IntegrationClient $client): JsonResponse
    {
        $d = $r->validate([
            'event_name' => ['required', 'string', function ($attr, $value, $fail) {
                if (! CanonicalEventSchema::where('event_name', $value)->where('status', 'ACTIVE')->exists()) {
                    $fail("[{$value}] is not a registered, active canonical event. See the canonical event schema catalogue.");
                }
            }],
            'endpoint' => 'required|url:https|max:500',
        ]);

        abort_unless($client->status === 'ACTIVE', 409, 'Only an ACTIVE connection may subscribe to webhooks.');

        // Q10 2026-09-29: one implementation shared with the admin webhook screen (WebhookSubscriptionService).
        $result = $this->auditedCall(fn () => app(\App\Application\Integrations\WebhookSubscriptionService::class)->subscribe($client, $d['event_name'], $d['endpoint']),
            'integration.webhook.created', 'integration_webhook_subscription', $client->id);

        return response()->json(['data' => ['id' => $result['subscription']->id, 'signing_secret' => $result['signing_secret']]], 201);
    }

    public function replayDeliveryAttempt(Request $r, IntegrationDeliveryAttempt $attempt, WebhookDeliveryService $delivery): JsonResponse
    {
        $message = DB::table('outbox_messages')->find($attempt->event_id);
        abort_unless($message, 404, 'The original event no longer exists.');

        $result = $this->auditedCall(
            fn () => $delivery->replay($attempt, json_decode($message->payload, true), $r->user()),
            'integration.webhook.replayed', 'integration_delivery_attempt', $attempt->id,
        );

        return response()->json(['data' => ['id' => $result->id, 'status' => $result->status, 'attempt' => $result->attempt]]);
    }

    public function health(IntegrationHealthService $service): JsonResponse
    {
        return response()->json(['data' => $service->summary()]);
    }
}
