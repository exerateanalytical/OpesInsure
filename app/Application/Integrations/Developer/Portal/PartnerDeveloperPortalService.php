<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal;

use App\Application\Audit\AuditWriter;
use App\Application\Integrations\Developer\DeveloperPortalService;
use App\Application\Integrations\Developer\OAuthScopeCatalogue;
use App\Models\IntegrationClient;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * S1 — partner developer portal (DEV-001..014) read model + self-service. Every method takes the partner's OWN
 * IntegrationClient (resolved from integration_client_developers for the signed-in user, never from request input),
 * so a partner developer can only ever see its own client's keys, request logs, usage, deliveries and limits.
 * Platform staff keep the /api/v1/developer/* routes (platform tenant only).
 */
final class PartnerDeveloperPortalService
{
    public const ROLES = ['OWNER', 'DEVELOPER', 'VIEWER'];

    /** Roles allowed to issue / revoke sandbox keys. */
    public const KEY_MANAGERS = ['OWNER', 'DEVELOPER'];

    public function __construct(private readonly DeveloperPortalService $portal, private readonly AuditWriter $audit) {}

    // ---- access ---------------------------------------------------------------------------------------------

    /** Active developer links of $user. @return list<object> */
    public function links(?User $user): array
    {
        if ($user === null || $user->status !== 'ACTIVE') {
            return [];
        }

        return DB::table('integration_client_developers as d')->join('integration_clients as c', 'c.id', '=', 'd.integration_client_id')
            ->where('d.user_id', $user->getKey())->where('d.status', 'ACTIVE')->whereNotIn('c.status', ['REVOKED'])
            ->orderBy('d.created_at')->get(['d.id', 'd.integration_client_id', 'd.role', 'c.name'])->all();
    }

    /** The client the user acts for: $wanted when it is one of theirs, else their first. */
    public function link(?User $user, ?string $wanted = null): ?object
    {
        $links = $this->links($user);
        foreach ($links as $l) {
            if ($wanted !== null && $l->integration_client_id === $wanted) {
                return $l;
            }
        }

        return $links[0] ?? null;
    }

    public function linkDeveloper(IntegrationClient $client, User $user, string $role, User $actor): object
    {
        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Unknown developer role.']);
        }
        DB::table('integration_client_developers')->upsert([[
            'id' => (string) Str::uuid(), 'integration_client_id' => $client->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'ACTIVE',
            'granted_by' => $actor->id, 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]], ['integration_client_id', 'user_id'], ['role', 'status', 'granted_by', 'revoked_at', 'updated_at']);
        $row = DB::table('integration_client_developers')->where(['integration_client_id' => $client->id, 'user_id' => $user->id])->first();
        $this->audit->record('integration.developer.linked', 'integration_client_developer', $row->id, ['integration_client_id' => $client->id, 'user_id' => $user->id, 'role' => $role]);

        return $row;
    }

    public function unlinkDeveloper(IntegrationClient $client, string $userId, User $actor): void
    {
        $row = DB::table('integration_client_developers')->where(['integration_client_id' => $client->id, 'user_id' => $userId])->first();
        abort_if($row === null, 404);
        DB::table('integration_client_developers')->where('id', $row->id)->update(['status' => 'REVOKED', 'revoked_at' => now(), 'updated_at' => now()]);
        $this->audit->record('integration.developer.unlinked', 'integration_client_developer', $row->id, ['integration_client_id' => $client->id, 'user_id' => $userId, 'actor_id' => $actor->id]);
    }

    // ---- DEV-001 home ---------------------------------------------------------------------------------------

    /** @return array<string, int|string|null> */
    public function summary(IntegrationClient $client): array
    {
        $since = now()->subDays(30)->toDateString();
        $u = DB::table('integration_client_usage')->where('integration_client_id', $client->id)->where('usage_date', '>=', $since)
            ->selectRaw('coalesce(sum(allowed_count),0) a, coalesce(sum(denied_count),0) d, coalesce(sum(rate_limited_count),0) r')->first();
        $failed = DB::table('integration_delivery_attempts as a')->join('integration_webhook_subscriptions as s', 's.id', '=', 'a.integration_webhook_subscription_id')
            ->where('s.integration_client_id', $client->id)->whereIn('a.status', ['RETRY_SCHEDULED', 'DEAD_LETTERED'])->where('a.created_at', '>=', now()->subDays(7))->count();

        return [
            'connection_status' => $client->status,
            'environment' => $client->environment,
            'active_keys' => DB::table('integration_client_keys')->where(['integration_client_id' => $client->id, 'status' => 'ACTIVE'])->count(),
            'calls_30d' => (int) $u->a,
            'denied_30d' => (int) $u->d + (int) $u->r,
            'webhook_subscriptions' => DB::table('integration_webhook_subscriptions')->where(['integration_client_id' => $client->id, 'status' => 'ACTIVE'])->count(),
            'failed_deliveries_7d' => $failed,
            'last_used_at' => $client->last_used_at?->toIso8601String(),
        ];
    }

    // ---- DEV-002 / DEV-008 docs & explorer -------------------------------------------------------------------

    /** Operations of the published OpenAPI document; partner-callable ones first. @return list<array<string, mixed>> */
    public function operations(bool $partnerOnly = true): array
    {
        $doc = $this->openApi();
        $ops = [];
        foreach (($doc['paths'] ?? []) as $path => $methods) {
            foreach ((array) $methods as $method => $op) {
                $scopes = $op['x-partner-scopes'] ?? null;
                if ($partnerOnly && $scopes === null) {
                    continue;
                }
                $ops[] = [
                    'operation_id' => $op['operationId'] ?? null, 'method' => strtoupper((string) $method), 'path' => (string) $path,
                    'tag' => ($op['tags'][0] ?? null), 'scopes' => $scopes === null ? '' : implode(', ', (array) $scopes),
                    'parameters' => implode(', ', array_map(fn ($p) => (string) ($p['name'] ?? ''), (array) ($op['parameters'] ?? []))),
                    'body_fields' => implode(', ', array_keys((array) ($op['requestBody']['content']['application/json']['schema']['properties'] ?? []))),
                ];
            }
        }

        return $ops;
    }

    /** @return array<string, mixed> */
    public function openApi(): array
    {
        $path = base_path('docs/api/openapi.json');

        return is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
    }

    /** DEV-008: a ready-to-run curl for one operation against the sandbox (token placeholder — never a real secret). */
    public function curlFor(string $method, string $path, string $bodyFields = ''): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $cmd = 'curl -X '.strtoupper($method).' "'.$base.$path.'" -H "Authorization: Bearer $ACCESS_TOKEN" -H "Accept: application/json"';
        if ($bodyFields !== '') {
            $body = [];
            foreach (array_filter(array_map('trim', explode(',', $bodyFields))) as $f) {
                $body[$f] = '…';
            }
            $cmd .= ' -H "Content-Type: application/json" -d \''.json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'\'';
        }

        return $cmd;
    }

    // ---- DEV-003 products ------------------------------------------------------------------------------------

    /** API products = the OAuth scope catalogue, with this client's grant. @return list<array<string, mixed>> */
    public function products(IntegrationClient $client): array
    {
        $out = [];
        foreach (OAuthScopeCatalogue::SCOPES as $scope => $def) {
            [$area, $access] = array_pad(explode('.', $scope, 2), 2, '');
            $out[] = ['product' => $area, 'scope' => $scope, 'access' => $access, 'description' => $def['description'], 'granted' => $client->hasScope($scope)];
        }

        return $out;
    }

    // ---- DEV-004 / DEV-007 keys & sandbox --------------------------------------------------------------------

    /** Keys of this client — never secrets (Passport stores them hashed; they are shown once at issue time). @return list<object> */
    public function keys(IntegrationClient $client): array
    {
        return DB::table('integration_client_keys')->where('integration_client_id', $client->id)->orderByDesc('created_at')
            ->get(['id', 'environment', 'oauth_client_id', 'label', 'status', 'last_used_at', 'created_at', 'revoked_at'])->all();
    }

    /** @return array<string, mixed> */
    public function sandbox(IntegrationClient $client): array
    {
        $base = rtrim((string) config('app.url'), '/');

        return [
            'connection_status' => $client->status,
            'sandbox_available' => in_array($client->status, DeveloperPortalService::SANDBOX_STATUSES, true),
            'base_url' => $base.'/api/v1',
            'token_url' => $base.'/oauth/token',
            'grant_type' => 'client_credentials',
            'sandbox_rate_limit_per_minute' => (int) $client->sandbox_rate_limit_per_minute,
            'sandbox_keys' => DB::table('integration_client_keys')->where(['integration_client_id' => $client->id, 'environment' => 'sandbox', 'status' => 'ACTIVE'])->count(),
            'scopes' => implode(', ', (array) $client->scopes),
        ];
    }

    /** Partner self-service: sandbox keys only (production credentials stay with platform staff). @return array{key:object, client_id:string, client_secret:string} */
    public function issueSandboxKey(IntegrationClient $client, object $link, ?string $label, User $actor): array
    {
        $this->assertKeyManager($link);

        return $this->portal->issueKey($client, 'sandbox', $label !== null && $label !== '' ? Str::limit($label, 120, '') : null, $actor);
    }

    public function revokeKey(IntegrationClient $client, object $link, string $keyId, User $actor): object
    {
        $this->assertKeyManager($link);
        // revokeKey itself scopes the lookup to $client — another client's key is a 404.
        return $this->portal->revokeKey($client, $keyId, $actor);
    }

    private function assertKeyManager(object $link): void
    {
        if (! in_array($link->role, self::KEY_MANAGERS, true)) {
            throw ValidationException::withMessages(['role' => __('developer_portal.ui.keys_role_required')]);
        }
    }

    // ---- DEV-010 / DEV-011 webhooks --------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function eventCatalogue(IntegrationClient $client): array
    {
        $subscribed = DB::table('integration_webhook_subscriptions')->where(['integration_client_id' => $client->id, 'status' => 'ACTIVE'])->pluck('event_name')->all();

        return DB::table('canonical_event_schemas')->where('status', 'ACTIVE')->where('privacy_classification', '!=', 'RESTRICTED')
            ->orderBy('event_name')->orderBy('version')->get(['event_name', 'version', 'description', 'privacy_classification', 'example_payload'])
            ->map(fn ($e) => ['event_name' => $e->event_name, 'version' => $e->version, 'description' => $e->description, 'privacy_classification' => $e->privacy_classification,
                'subscribed' => in_array($e->event_name, $subscribed, true), 'example_payload' => $e->example_payload])->all();
    }

    /** @return list<array<string, mixed>> */
    public function subscriptions(IntegrationClient $client): array
    {
        return DB::table('integration_webhook_subscriptions')->where('integration_client_id', $client->id)->orderBy('event_name')
            ->get(['id', 'event_name', 'endpoint_encrypted', 'status', 'created_at'])
            ->map(fn ($s) => ['event_name' => $s->event_name, 'endpoint_host' => $this->host($s->endpoint_encrypted), 'status' => $s->status, 'created_at' => $s->created_at])->all();
    }

    /** DEV-011 delivery logs of this client's subscriptions. @return list<array<string, mixed>> */
    public function deliveries(IntegrationClient $client, ?string $status = null, int $limit = 200): array
    {
        return DB::table('integration_delivery_attempts as a')->join('integration_webhook_subscriptions as s', 's.id', '=', 'a.integration_webhook_subscription_id')
            ->where('s.integration_client_id', $client->id)->when($status, fn ($q) => $q->where('a.status', $status))
            ->orderByDesc('a.created_at')->limit($limit)
            ->get(['a.created_at', 's.event_name', 'a.event_id', 'a.attempt', 'a.status', 'a.response_status', 'a.failure_reason', 'a.next_attempt_at', 'a.delivered_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    private function host(?string $encrypted): string
    {
        try {
            return (string) (parse_url((string) Crypt::decryptString((string) $encrypted), PHP_URL_HOST) ?: '—');
        } catch (Throwable) {
            return '—';
        }
    }

    // ---- DEV-012 / DEV-013 request logs, usage, limits -------------------------------------------------------

    /** Writes one request-log row (metadata only). Called by AuthenticateIntegrationClient; never throws. */
    public function logRequest(IntegrationClient $client, ?string $keyId, string $environment, string $method, string $route, string $scope, int $status, string $outcome, ?string $reason, ?int $durationMs, ?string $ip, ?string $requestId): void
    {
        rescue(fn () => DB::table('integration_request_logs')->insert([
            'id' => (string) Str::uuid(), 'integration_client_id' => $client->id, 'integration_client_key_id' => $keyId, 'environment' => $environment,
            'method' => Str::limit(strtoupper($method), 8, ''), 'route' => Str::limit($route, 255, ''), 'scope' => Str::limit($scope, 120, ''),
            'status_code' => $status, 'outcome' => $outcome, 'denial_reason' => $reason, 'duration_ms' => $durationMs, 'ip' => $ip,
            'request_id' => $requestId !== null ? Str::limit($requestId, 80, '') : null, 'created_at' => now(),
        ]), null, false);
    }

    /** @param array{environment?:?string, outcome?:?string, from?:?string, to?:?string} $filters @return list<array<string, mixed>> */
    public function requestLogs(IntegrationClient $client, array $filters = [], int $limit = 200): array
    {
        return DB::table('integration_request_logs')->where('integration_client_id', $client->id)
            ->when($filters['environment'] ?? null, fn ($q, $v) => $q->where('environment', $v))
            ->when($filters['outcome'] ?? null, fn ($q, $v) => $q->where('outcome', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', \Carbon\CarbonImmutable::parse($v)->addDay()))
            ->orderByDesc('created_at')->limit($limit)
            ->get(['created_at', 'environment', 'method', 'route', 'scope', 'status_code', 'outcome', 'denial_reason', 'duration_ms', 'request_id'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** Daily usage (30 days by default) and limits. @return array{limits: array<string,int>, days: list<array<string,mixed>>, totals: array<string,int>} */
    public function usage(IntegrationClient $client, ?string $from = null, ?string $to = null): array
    {
        $rows = collect($this->portal->usage($client, $from ?? now()->subDays(30)->toDateString(), $to ?? now()->toDateString()));
        $days = $rows->groupBy(fn ($r) => $r->usage_date.'|'.$r->environment)->map(fn ($g, $k) => [
            'usage_date' => explode('|', $k)[0], 'environment' => explode('|', $k)[1],
            'allowed_count' => (int) $g->sum('allowed_count'), 'denied_count' => (int) $g->sum('denied_count'), 'rate_limited_count' => (int) $g->sum('rate_limited_count'),
        ])->values()->all();

        return [
            'limits' => ['rate_limit_per_minute' => (int) $client->rate_limit_per_minute, 'sandbox_rate_limit_per_minute' => (int) $client->sandbox_rate_limit_per_minute],
            'days' => $days,
            'totals' => ['allowed_count' => (int) $rows->sum('allowed_count'), 'denied_count' => (int) $rows->sum('denied_count'), 'rate_limited_count' => (int) $rows->sum('rate_limited_count')],
        ];
    }
}
