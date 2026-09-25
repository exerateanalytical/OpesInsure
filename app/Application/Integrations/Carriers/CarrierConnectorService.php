<?php

declare(strict_types=1);

namespace App\Application\Integrations\Carriers;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Integrations\ExternalRecordMappingService;
use App\Models\{ExternalRecordMapping,IntegrationClient,User};
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{Crypt,DB,Http};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-API-007 — carrier connector framework (agent B3).
 *
 * One config row per carrier (carrier_connector_configs). Outbound traffic stays in the existing
 * carrier_exchange_messages table (whoever queues a message — e.g. ClaimCarrierExchangeService —
 * keeps doing so); this service delivers it:
 *  - API transport: POST to base_url + endpoints[message_type], signed with the carrier's key from
 *    claim_carrier_signing_keys (the Batch 11 scheme, see CarrierMessageSigner), with a timeout;
 *  - transient failure (network, 408, 429, 5xx) → RETRY_PENDING with exponential backoff
 *    (base_backoff_seconds · 2^(attempt-1)) until max_attempts;
 *  - exhausted retries, a non-retryable 4xx, a MANUAL / DISABLED / missing config → MANUAL_FALLBACK
 *    (fallback_queued_at) for an operator to send by hand, requeue or cancel;
 *  - record sync over external_record_mappings (through ExternalRecordMappingService) with explicit
 *    conflict detection + resolution — never last-write-wins.
 */
final class CarrierConnectorService
{
    public const DISPATCHABLE = ['QUEUED', 'RETRY_PENDING'];

    public const MAX_BACKOFF_SECONDS = 86_400;

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly CarrierMessageSigner $signer,
        private readonly ExternalRecordMappingService $mappings,
    ) {}

    /** @param array{transport:string, base_url?:?string, endpoints?:array<string,string>, signing_key_id?:?string, integration_client_id?:?string, timeout_seconds?:int, max_attempts?:int, base_backoff_seconds?:int, status?:string} $data */
    public function configure(string $carrierId, array $data, User $actor): object
    {
        if ($data['transport'] === 'API') {
            if (empty($data['base_url']) || empty($data['signing_key_id'])) {
                throw ValidationException::withMessages(['transport' => 'An API connector needs a base_url and a signing_key_id.']);
            }
            if (! DB::table('claim_carrier_signing_keys')->where(['carrier_id' => $carrierId, 'key_id' => $data['signing_key_id'], 'status' => 'ACTIVE'])->exists()) {
                throw ValidationException::withMessages(['signing_key_id' => 'No ACTIVE signing key with this id for the carrier.']);
            }
        }

        return DB::transaction(function () use ($carrierId, $data, $actor) {
            $existing = DB::table('carrier_connector_configs')->where('carrier_id', $carrierId)->lockForUpdate()->first();
            $row = [
                'integration_client_id' => $data['integration_client_id'] ?? null,
                'transport' => $data['transport'],
                'base_url_encrypted' => ! empty($data['base_url']) ? Crypt::encryptString((string) $data['base_url']) : null,
                'endpoints' => json_encode((object) ($data['endpoints'] ?? []), JSON_THROW_ON_ERROR),
                'signing_key_id' => $data['signing_key_id'] ?? null,
                'timeout_seconds' => $data['timeout_seconds'] ?? 15,
                'max_attempts' => $data['max_attempts'] ?? 5,
                'base_backoff_seconds' => $data['base_backoff_seconds'] ?? 60,
                'status' => $data['status'] ?? 'ACTIVE',
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('carrier_connector_configs')->where('id', $existing->id)->update($row);
                $id = $existing->id;
            } else {
                $id = (string) Str::uuid();
                DB::table('carrier_connector_configs')->insert($row + ['id' => $id, 'carrier_id' => $carrierId, 'created_at' => now()]);
            }
            $meta = ['carrier_id' => $carrierId, 'transport' => $data['transport'], 'status' => $row['status'], 'signing_key_id' => $row['signing_key_id']];
            $this->audit->record('integration.carrier_connector.configured', 'carrier_connector_config', $id, $meta);
            $this->outbox->record('integration.carrier_connector.configured', 'carrier_connector_config', $id, $meta);

            return $this->present(DB::table('carrier_connector_configs')->find($id));
        });
    }

    public function config(string $carrierId): ?object
    {
        return DB::table('carrier_connector_configs')->where('carrier_id', $carrierId)->first();
    }

    /** Hides the encrypted URL; exposes only whether one is set. */
    public function present(object $config): object
    {
        $out = (array) $config;
        unset($out['base_url_encrypted']);
        $out['has_base_url'] = $config->base_url_encrypted !== null;
        $out['endpoints'] = json_decode((string) $config->endpoints, true) ?: [];

        return (object) $out;
    }

    /** Dispatches every due OUTBOUND message; @return array{sent:int, retry:int, fallback:int, skipped:int} */
    public function dispatchDue(int $limit = 100): array
    {
        $stats = ['sent' => 0, 'retry' => 0, 'fallback' => 0, 'skipped' => 0];
        $ids = DB::table('carrier_exchange_messages')->where('direction', 'OUTBOUND')->whereIn('status', self::DISPATCHABLE)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('created_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $status = $this->dispatch((string) $id)->status;
            $key = ['SENT' => 'sent', 'RETRY_PENDING' => 'retry', 'MANUAL_FALLBACK' => 'fallback'][$status] ?? 'skipped';
            $stats[$key]++;
        }

        return $stats;
    }

    public function dispatch(string $messageId): object
    {
        // Claim the row first so two dispatchers never send the same message.
        $message = DB::transaction(function () use ($messageId) {
            $m = DB::table('carrier_exchange_messages')->where(['id' => $messageId, 'direction' => 'OUTBOUND'])->lockForUpdate()->first();
            abort_if($m === null, 404);
            if (! in_array($m->status, self::DISPATCHABLE, true)) {
                return $m;
            }
            DB::table('carrier_exchange_messages')->where('id', $messageId)->update(['status' => 'SENDING', 'last_attempted_at' => now(), 'updated_at' => now()]);

            return $m;
        });
        if (! in_array($message->status, self::DISPATCHABLE, true)) {
            return $message;
        }

        $config = $this->config($message->carrier_id);
        if ($config === null || $config->transport !== 'API' || $config->status !== 'ACTIVE') {
            return $this->toFallback($message, $config === null ? 'no_connector_config' : ($config->status !== 'ACTIVE' ? 'connector_disabled' : 'manual_transport'), null);
        }
        $endpoints = json_decode((string) $config->endpoints, true) ?: [];
        $path = $endpoints[$message->message_type] ?? null;
        if ($path === null) {
            return $this->toFallback($message, 'no_endpoint_for_message_type', null);
        }

        $body = (string) $message->payload;
        $url = rtrim(Crypt::decryptString($config->base_url_encrypted), '/').'/'.ltrim((string) $path, '/');
        $headers = $this->signer->sign($message->carrier_id, (string) $config->signing_key_id, $body) + [
            'Content-Type' => 'application/json',
            'Idempotency-Key' => (string) $message->correlation_id,
            'X-OpesInsure-Message-Type' => (string) $message->message_type,
        ];

        try {
            $response = Http::withHeaders($headers)->timeout((int) $config->timeout_seconds)->withBody($body, 'application/json')->post($url);
        } catch (ConnectionException $e) {
            return $this->failed($message, $config, 'connection_error', null);
        }

        $status = $response->status();
        if ($response->successful()) {
            $reference = $response->json('reference') ?? $response->json('external_reference');
            DB::table('carrier_exchange_messages')->where('id', $message->id)->update([
                'status' => 'SENT', 'sent_at' => now(), 'attempt_count' => DB::raw('attempt_count + 1'), 'last_response_status' => $status,
                'external_reference' => $reference !== null ? (string) $reference : $message->external_reference,
                'next_attempt_at' => null, 'failure_reason' => null, 'updated_at' => now(),
            ]);
            $meta = ['carrier_id' => $message->carrier_id, 'message_type' => $message->message_type, 'claim_id' => $message->claim_id];
            $this->audit->record('integration.carrier_message.sent', 'carrier_exchange_message', $message->id, $meta + ['http_status' => $status]);
            $this->outbox->record('integration.carrier_message.sent', 'carrier_exchange_message', $message->id, $meta);

            return DB::table('carrier_exchange_messages')->find($message->id);
        }

        $retryable = $status >= 500 || in_array($status, [408, 429], true);

        return $retryable ? $this->failed($message, $config, 'http_'.$status, $status) : $this->toFallback($message, 'http_'.$status, $status, true);
    }

    public static function backoffSeconds(int $base, int $attempt): int
    {
        return (int) min(self::MAX_BACKOFF_SECONDS, $base * (2 ** max(0, $attempt - 1)));
    }

    private function failed(object $message, object $config, string $reason, ?int $status): object
    {
        $attempt = (int) $message->attempt_count + 1;
        if ($attempt >= (int) $config->max_attempts) {
            return $this->toFallback($message, 'retries_exhausted:'.$reason, $status, true);
        }
        DB::table('carrier_exchange_messages')->where('id', $message->id)->update([
            'status' => 'RETRY_PENDING', 'attempt_count' => $attempt, 'failure_reason' => $reason, 'last_response_status' => $status,
            'next_attempt_at' => now()->addSeconds(self::backoffSeconds((int) $config->base_backoff_seconds, $attempt)), 'updated_at' => now(),
        ]);
        $this->audit->record('integration.carrier_message.retry_scheduled', 'carrier_exchange_message', $message->id, ['attempt' => $attempt], $reason);

        return DB::table('carrier_exchange_messages')->find($message->id);
    }

    private function toFallback(object $message, string $reason, ?int $status, bool $attempted = false): object
    {
        DB::table('carrier_exchange_messages')->where('id', $message->id)->update([
            'status' => 'MANUAL_FALLBACK', 'fallback_queued_at' => now(), 'fallback_reason' => $reason, 'failure_reason' => $reason,
            'last_response_status' => $status, 'next_attempt_at' => null,
            'attempt_count' => $attempted ? DB::raw('attempt_count + 1') : DB::raw('attempt_count'), 'updated_at' => now(),
        ]);
        $meta = ['carrier_id' => $message->carrier_id, 'message_type' => $message->message_type, 'claim_id' => $message->claim_id, 'reason' => $reason];
        $this->audit->record('integration.carrier_message.fallback_queued', 'carrier_exchange_message', $message->id, $meta, $reason);
        $this->outbox->record('integration.carrier_message.fallback_queued', 'carrier_exchange_message', $message->id, $meta);

        return DB::table('carrier_exchange_messages')->find($message->id);
    }

    /** @return list<object> */
    public function fallbackQueue(?string $carrierId = null): array
    {
        return DB::table('carrier_exchange_messages')->where('status', 'MANUAL_FALLBACK')
            ->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId))->orderBy('fallback_queued_at')
            ->get(['id', 'carrier_id', 'claim_id', 'message_type', 'correlation_id', 'attempt_count', 'fallback_reason', 'fallback_queued_at', 'last_response_status', 'payload'])
            ->map(fn ($m) => (object) [...(array) $m, 'payload' => json_decode((string) $m->payload, true)])->all();
    }

    /** @param 'SENT_MANUALLY'|'REQUEUED'|'CANCELLED' $resolution */
    public function resolveFallback(string $messageId, string $resolution, string $note, ?string $externalReference, User $actor): object
    {
        return DB::transaction(function () use ($messageId, $resolution, $note, $externalReference, $actor) {
            $m = DB::table('carrier_exchange_messages')->where('id', $messageId)->lockForUpdate()->first();
            abort_if($m === null, 404);
            if ($m->status !== 'MANUAL_FALLBACK') {
                throw ValidationException::withMessages(['status' => 'Only a message in the manual fallback queue can be resolved.']);
            }
            $update = ['fallback_resolved_at' => now(), 'fallback_resolved_by' => $actor->id, 'fallback_resolution' => $resolution, 'fallback_note' => $note, 'updated_at' => now()];
            $update += match ($resolution) {
                'SENT_MANUALLY' => ['status' => 'SENT', 'sent_at' => now(), 'external_reference' => $externalReference ?? $m->external_reference],
                'REQUEUED' => ['status' => 'RETRY_PENDING', 'attempt_count' => 0, 'next_attempt_at' => now(), 'fallback_queued_at' => null],
                'CANCELLED' => ['status' => 'CANCELLED'],
            };
            DB::table('carrier_exchange_messages')->where('id', $messageId)->update($update);
            $meta = ['carrier_id' => $m->carrier_id, 'message_type' => $m->message_type, 'resolution' => $resolution];
            $this->audit->record('integration.carrier_message.fallback_resolved', 'carrier_exchange_message', $messageId, $meta, $note);
            $this->outbox->record('integration.carrier_message.fallback_resolved', 'carrier_exchange_message', $messageId, $meta);

            return DB::table('carrier_exchange_messages')->find($messageId);
        });
    }

    /**
     * Records the carrier's view of a record. New → mapped. Same version → re-synced. A different external
     * version on a record OpesInsure owns (source_of_truth OPESINSURE) → CONFLICT for human resolution; on a
     * PARTNER-owned record the newer external version is accepted.
     *
     * @param array{record_type:string, external_record_id:string, opesinsure_record_id:string, external_version?:?string, last_external_modified_at?:?string, fields?:array} $data
     */
    public function syncRecord(string $carrierId, array $data): ExternalRecordMapping
    {
        $client = $this->mappingClient($carrierId);
        $existing = $this->mappings->findByExternalId($client, $data['record_type'], $data['external_record_id']);
        $version = $data['external_version'] ?? null;

        if ($existing === null) {
            return $this->mappings->map($client, $data['record_type'], $data['external_record_id'], $data['opesinsure_record_id'], [
                'external_version' => $version, 'last_external_modified_at' => $data['last_external_modified_at'] ?? null, 'metadata' => ['carrier_id' => $carrierId],
            ]);
        }
        if ($existing->opesinsure_record_id !== $data['opesinsure_record_id']) {
            throw ValidationException::withMessages(['external_record_id' => 'This external record is already mapped to a different OpesInsure record.']);
        }
        if ($version === null || $version === $existing->external_version) {
            $existing->update(['last_synchronized_at' => now()]);

            return $existing->refresh();
        }
        if ($existing->source_of_truth === 'PARTNER') {
            $existing->update(['external_version' => $version, 'last_external_modified_at' => $data['last_external_modified_at'] ?? now(), 'last_synchronized_at' => now(), 'synchronization_status' => 'SYNCED']);

            return $existing->refresh();
        }

        $mapping = $this->mappings->flagConflict($existing, 'EXTERNAL_CHANGE_ON_OWNED_RECORD', [
            'carrier_id' => $carrierId, 'incoming_external_version' => $version, 'known_external_version' => $existing->external_version, 'fields' => $data['fields'] ?? [],
        ]);
        DB::table('external_record_mappings')->where('id', $mapping->id)->update(['conflict_detected_at' => now(), 'conflict_resolved_at' => null, 'conflict_resolution' => null, 'conflict_resolved_by' => null]);
        $this->outbox->record('integration.record_mapping.conflict_detected', 'external_record_mapping', $mapping->id, ['carrier_id' => $carrierId, 'record_type' => $mapping->record_type]);

        return $mapping->refresh();
    }

    /** @param 'KEEP_OPESINSURE'|'ACCEPT_EXTERNAL' $resolution */
    public function resolveConflict(string $mappingId, string $resolution, string $note, User $actor): ExternalRecordMapping
    {
        return DB::transaction(function () use ($mappingId, $resolution, $note, $actor) {
            $mapping = ExternalRecordMapping::whereKey($mappingId)->lockForUpdate()->firstOrFail();
            if ($mapping->synchronization_status !== 'CONFLICT') {
                throw ValidationException::withMessages(['status' => 'This mapping has no open conflict.']);
            }
            $incoming = $mapping->metadata['conflict']['incoming_external_version'] ?? null;
            $mapping->update([
                'synchronization_status' => 'SYNCED', 'conflict_status' => null, 'last_synchronized_at' => now(),
                'external_version' => $resolution === 'ACCEPT_EXTERNAL' ? $incoming : $mapping->external_version,
                'opesinsure_version' => $resolution === 'ACCEPT_EXTERNAL' ? $mapping->opesinsure_version + 1 : $mapping->opesinsure_version,
            ]);
            DB::table('external_record_mappings')->where('id', $mappingId)->update(['conflict_resolved_at' => now(), 'conflict_resolved_by' => $actor->id, 'conflict_resolution' => $resolution]);
            $meta = ['record_type' => $mapping->record_type, 'resolution' => $resolution];
            $this->audit->record('integration.record_mapping.conflict_resolved', 'external_record_mapping', $mappingId, $meta, $note);
            $this->outbox->record('integration.record_mapping.conflict_resolved', 'external_record_mapping', $mappingId, $meta);

            return $mapping->refresh();
        });
    }

    private function mappingClient(string $carrierId): IntegrationClient
    {
        $config = $this->config($carrierId);
        $client = $config?->integration_client_id ? IntegrationClient::find($config->integration_client_id) : null;
        if ($client === null) {
            throw ValidationException::withMessages(['carrier_id' => 'The carrier connector has no integration client to hold record mappings.']);
        }

        return $client;
    }
}
