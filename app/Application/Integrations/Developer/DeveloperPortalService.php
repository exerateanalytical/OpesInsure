<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\{IntegrationClient,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\ClientRepository;

/**
 * REQ-API-006 developer portal API on top of the existing integration_clients lifecycle
 * (IntegrationClientLifecycleService stays the only place that registers / transitions a client):
 *  - extra credentials per client (sandbox keys; additional production keys) — each a real Passport
 *    client-credentials client recorded in integration_client_keys;
 *  - per-client usage metering (integration_client_usage daily buckets) and per-environment rate limits;
 *  - tenant API consent: a tenant grants a client scoped, delegated access to its data (scopes ⊆ the
 *    client's own scopes); AuthenticateIntegrationClient enforces it on "on behalf of tenant" calls.
 */
final class DeveloperPortalService
{
    /** Statuses in which a sandbox key may be issued / used. */
    public const SANDBOX_STATUSES = ['SANDBOX_ENABLED', 'CERTIFICATION', 'PRODUCTION_APPROVED', 'ACTIVE'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox, private readonly ClientRepository $clients) {}

    /** @return array{key:object, client_id:string, client_secret:string} */
    public function issueKey(IntegrationClient $client, string $environment, ?string $label, User $actor): array
    {
        $allowed = $environment === 'sandbox' ? self::SANDBOX_STATUSES : ['PRODUCTION_APPROVED', 'ACTIVE'];
        if (! in_array($client->status, $allowed, true)) {
            throw ValidationException::withMessages(['environment' => "A {$environment} key cannot be issued while the connection is {$client->status}."]);
        }

        return DB::transaction(function () use ($client, $environment, $label, $actor) {
            $oauth = $this->clients->createClientCredentialsGrantClient($client->name.' ('.$environment.')');
            $id = (string) Str::uuid();
            DB::table('integration_client_keys')->insert([
                'id' => $id, 'integration_client_id' => $client->id, 'environment' => $environment, 'oauth_client_id' => $oauth->getKey(),
                'label' => $label, 'status' => 'ACTIVE', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $meta = ['integration_client_id' => $client->id, 'environment' => $environment];
            $this->audit->record('integration.client_key.issued', 'integration_client_key', $id, $meta);
            $this->outbox->record('integration.client_key.issued', 'integration_client_key', $id, $meta);

            return ['key' => DB::table('integration_client_keys')->find($id), 'client_id' => (string) $oauth->getKey(), 'client_secret' => (string) $oauth->plainSecret];
        });
    }

    public function revokeKey(IntegrationClient $client, string $keyId, User $actor): object
    {
        return DB::transaction(function () use ($client, $keyId, $actor) {
            $key = DB::table('integration_client_keys')->where(['id' => $keyId, 'integration_client_id' => $client->id])->lockForUpdate()->first();
            abort_if($key === null, 404);
            if ($key->status !== 'REVOKED') {
                DB::table('integration_client_keys')->where('id', $keyId)->update(['status' => 'REVOKED', 'revoked_at' => now(), 'revoked_by' => $actor->id, 'updated_at' => now()]);
                DB::table('oauth_clients')->where('id', $key->oauth_client_id)->update(['revoked' => true]);
                DB::table('oauth_access_tokens')->where('client_id', $key->oauth_client_id)->update(['revoked' => true]);
                $meta = ['integration_client_id' => $client->id, 'environment' => $key->environment];
                $this->audit->record('integration.client_key.revoked', 'integration_client_key', $keyId, $meta);
                $this->outbox->record('integration.client_key.revoked', 'integration_client_key', $keyId, $meta);
            }

            return DB::table('integration_client_keys')->find($keyId);
        });
    }

    public function setRateLimits(IntegrationClient $client, int $production, int $sandbox, User $actor): IntegrationClient
    {
        $old = ['rate_limit_per_minute' => $client->rate_limit_per_minute, 'sandbox_rate_limit_per_minute' => $client->sandbox_rate_limit_per_minute];
        $client->update(['rate_limit_per_minute' => $production, 'sandbox_rate_limit_per_minute' => $sandbox]);
        $this->audit->recordChange('integration.client.rate_limits_changed', 'integration_client', $client->id, $old,
            ['rate_limit_per_minute' => $production, 'sandbox_rate_limit_per_minute' => $sandbox], 'rate_limit_update', ['actor_id' => $actor->id]);

        return $client->refresh();
    }

    /**
     * Resolves which integration client (and environment) an OAuth client id belongs to: the primary
     * credential on integration_clients, or an ACTIVE row in integration_client_keys.
     *
     * @return array{client:IntegrationClient, environment:string, key_id:?string}|null
     */
    public function resolveCredential(string $oauthClientId): ?array
    {
        $client = IntegrationClient::where('oauth_client_id', $oauthClientId)->first();
        if ($client !== null) {
            return ['client' => $client, 'environment' => (string) ($client->environment ?: 'production'), 'key_id' => null];
        }
        $key = DB::table('integration_client_keys')->where(['oauth_client_id' => $oauthClientId, 'status' => 'ACTIVE'])->first();
        if ($key === null || ! ($client = IntegrationClient::find($key->integration_client_id))) {
            return null;
        }

        return ['client' => $client, 'environment' => (string) $key->environment, 'key_id' => (string) $key->id];
    }

    /** @param 'allowed'|'denied'|'rate_limited' $outcome */
    public function meter(IntegrationClient $client, string $environment, string $scope, string $outcome): void
    {
        $col = ['allowed' => 'allowed_count', 'denied' => 'denied_count', 'rate_limited' => 'rate_limited_count'][$outcome];
        DB::statement(
            "insert into integration_client_usage (id, integration_client_id, usage_date, environment, scope, {$col}, created_at, updated_at)
             values (?, ?, ?, ?, ?, 1, now(), now())
             on conflict (integration_client_id, usage_date, environment, scope)
             do update set {$col} = integration_client_usage.{$col} + 1, updated_at = now()",
            [(string) Str::uuid(), $client->id, now()->toDateString(), $environment, Str::limit($scope, 120, '')],
        );
    }

    /** @return list<object> */
    public function usage(IntegrationClient $client, string $from, string $to): array
    {
        return DB::table('integration_client_usage')->where('integration_client_id', $client->id)
            ->whereBetween('usage_date', [$from, $to])->orderBy('usage_date')->orderBy('environment')->orderBy('scope')
            ->get(['usage_date', 'environment', 'scope', 'allowed_count', 'denied_count', 'rate_limited_count'])->all();
    }

    /** @param list<string> $scopes */
    public function grantConsent(IntegrationClient $client, string $tenantId, array $scopes, ?string $expiresAt, User $actor): object
    {
        if ($client->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['integration_client' => 'Consent can only be granted to an ACTIVE connection.']);
        }
        $scopes = array_values(array_unique($scopes));
        $beyond = array_values(array_filter($scopes, fn (string $s) => ! $client->hasScope($s)));
        if ($beyond !== []) {
            throw ValidationException::withMessages(['scopes' => 'Consent cannot exceed the connection\'s own scopes: '.implode(', ', $beyond)]);
        }

        return DB::transaction(function () use ($client, $tenantId, $scopes, $expiresAt, $actor) {
            DB::table('integration_client_consents')->where(['integration_client_id' => $client->id, 'tenant_id' => $tenantId, 'status' => 'GRANTED'])
                ->update(['status' => 'REVOKED', 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => 'superseded', 'updated_at' => now()]);
            $id = (string) Str::uuid();
            DB::table('integration_client_consents')->insert([
                'id' => $id, 'integration_client_id' => $client->id, 'tenant_id' => $tenantId, 'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
                'status' => 'GRANTED', 'granted_by' => $actor->id, 'granted_at' => now(), 'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $meta = ['integration_client_id' => $client->id, 'tenant_id' => $tenantId, 'scopes' => $scopes, 'expires_at' => $expiresAt];
            $this->audit->record('integration.consent.granted', 'integration_client_consent', $id, $meta);
            $this->outbox->record('integration.consent.granted', 'integration_client_consent', $id, $meta);

            return DB::table('integration_client_consents')->find($id);
        });
    }

    public function revokeConsent(string $consentId, string $tenantId, string $reason, User $actor): object
    {
        return DB::transaction(function () use ($consentId, $tenantId, $reason, $actor) {
            $consent = DB::table('integration_client_consents')->where(['id' => $consentId, 'tenant_id' => $tenantId])->lockForUpdate()->first();
            abort_if($consent === null, 404);
            if ($consent->status !== 'GRANTED') {
                throw ValidationException::withMessages(['status' => 'Only a granted consent can be revoked.']);
            }
            DB::table('integration_client_consents')->where('id', $consentId)->update(['status' => 'REVOKED', 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $reason, 'updated_at' => now()]);
            $meta = ['integration_client_id' => $consent->integration_client_id, 'tenant_id' => $tenantId];
            $this->audit->record('integration.consent.revoked', 'integration_client_consent', $consentId, $meta, $reason);
            $this->outbox->record('integration.consent.revoked', 'integration_client_consent', $consentId, $meta);

            return DB::table('integration_client_consents')->find($consentId);
        });
    }

    /** The live consent letting $client act for $tenantId with $scope ('*' = any scope the consent holds). */
    public function activeConsent(IntegrationClient $client, string $tenantId, string $scope): ?object
    {
        $consent = DB::table('integration_client_consents')->where(['integration_client_id' => $client->id, 'tenant_id' => $tenantId, 'status' => 'GRANTED'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('granted_at')->first();
        if ($consent === null) {
            return null;
        }
        $scopes = (array) json_decode((string) $consent->scopes, true);

        return ($scope === '*' || in_array($scope, $scopes, true)) ? $consent : null;
    }
}
