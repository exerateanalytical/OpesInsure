<?php
declare(strict_types=1);
namespace App\Application\Integrations;

use App\Application\Audit\AuditWriter;
use App\Models\{IntegrationClient,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\ClientRepository;

/**
 * DRAFT -> TECHNICAL_REVIEW -> SANDBOX_ENABLED -> CERTIFICATION -> PRODUCTION_APPROVED -> ACTIVE,
 * with RESTRICTED/SUSPENDED/REVOKED reachable as an exceptional side-branch from any
 * non-terminal state. REVOKED is terminal. Every transition is recorded twice: a
 * per-client history row (for the connection's own timeline) and the shared
 * tamper-evident audit_log (for platform-wide security review).
 */
final class IntegrationClientLifecycleService
{
    private const FORWARD = [
        'DRAFT' => 'TECHNICAL_REVIEW',
        'TECHNICAL_REVIEW' => 'SANDBOX_ENABLED',
        'SANDBOX_ENABLED' => 'CERTIFICATION',
        'CERTIFICATION' => 'PRODUCTION_APPROVED',
        'PRODUCTION_APPROVED' => 'ACTIVE',
    ];

    private const EXCEPTIONAL = ['RESTRICTED', 'SUSPENDED', 'REVOKED'];

    public function __construct(private readonly AuditWriter $audit, private readonly ClientRepository $clients) {}

    /**
     * Registers the connection's own metadata AND a real Passport client-credentials
     * OAuth client, which is what actually authenticates requests. Returns the
     * plaintext secret exactly once — it is never recoverable again.
     */
    public function register(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            $oauthClient = $this->clients->createClientCredentialsGrantClient($data['name']);

            $integrationClient = IntegrationClient::create([
                ...$data,
                'client_id' => (string) $oauthClient->getKey(),
                'oauth_client_id' => $oauthClient->getKey(),
                'client_secret_hash' => null,
                'status' => 'DRAFT',
                'environment' => $data['environment'] ?? 'sandbox',
            ]);

            $this->recordTransition($integrationClient, null, 'DRAFT', 'REGISTERED', $actor);

            return [
                'integration_client' => $integrationClient,
                'client_id' => (string) $oauthClient->getKey(),
                'client_secret' => $oauthClient->plainSecret,
            ];
        });
    }

    public function advance(IntegrationClient $client, string $reasonCode, ?string $notes, User $actor): IntegrationClient
    {
        $next = self::FORWARD[$client->status] ?? null;

        if ($next === null) {
            throw ValidationException::withMessages(['status' => "There is no forward transition from {$client->status}."]);
        }

        return $this->transition($client, $next, $reasonCode, $notes, $actor);
    }

    public function restrict(IntegrationClient $client, string $reasonCode, string $notes, User $actor): IntegrationClient
    {
        $this->guardNotTerminal($client);

        return $this->transition($client, 'RESTRICTED', $reasonCode, $notes, $actor);
    }

    public function suspend(IntegrationClient $client, string $reasonCode, string $notes, User $actor): IntegrationClient
    {
        $this->guardNotTerminal($client);

        return $this->transition($client, 'SUSPENDED', $reasonCode, $notes, $actor, ['revoked_at' => null]);
    }

    public function reinstate(IntegrationClient $client, string $notes, User $actor): IntegrationClient
    {
        if (! in_array($client->status, ['SUSPENDED', 'RESTRICTED'], true)) {
            throw ValidationException::withMessages(['status' => 'Only a suspended or restricted connection can be reinstated.']);
        }

        return $this->transition($client, 'ACTIVE', 'REINSTATED', $notes, $actor);
    }

    public function revoke(IntegrationClient $client, string $reasonCode, string $notes, User $actor): IntegrationClient
    {
        $this->guardNotTerminal($client);

        return $this->transition($client, 'REVOKED', $reasonCode, $notes, $actor, [
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
            'revocation_reason' => $notes,
        ]);
    }

    private function guardNotTerminal(IntegrationClient $client): void
    {
        if ($client->status === 'REVOKED') {
            throw ValidationException::withMessages(['status' => 'A revoked connection cannot be changed.']);
        }
    }

    private function transition(IntegrationClient $client, string $to, string $reasonCode, ?string $notes, User $actor, array $extra = []): IntegrationClient
    {
        return DB::transaction(function () use ($client, $to, $reasonCode, $notes, $actor, $extra) {
            $client = IntegrationClient::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $from = $client->status;

            $timestamps = match ($to) {
                'CERTIFICATION' => [],
                'PRODUCTION_APPROVED' => ['certified_at' => now(), 'certified_by' => $actor->id],
                'ACTIVE' => $client->activated_at ? [] : ['activated_at' => now()],
                default => [],
            };

            $client->update(['status' => $to, ...$timestamps, ...$extra]);
            $this->recordTransition($client, $from, $to, $reasonCode, $actor, $notes);

            return $client->refresh();
        });
    }

    private function recordTransition(IntegrationClient $client, ?string $from, string $to, string $reasonCode, User $actor, ?string $notes = null): void
    {
        DB::table('integration_client_status_history')->insert([
            'id' => (string) Str::uuid(),
            'integration_client_id' => $client->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason_code' => $reasonCode,
            'notes' => $notes,
            'actor_id' => $actor->id,
            'occurred_at' => now(),
        ]);
        $this->audit->record('integration.client.status_changed', 'integration_client', $client->id, ['from' => $from, 'to' => $to], $reasonCode);
    }
}
