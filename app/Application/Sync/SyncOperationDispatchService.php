<?php

declare(strict_types=1);

namespace App\Application\Sync;

use App\Application\Agents\AgentClientIntakeService;
use App\Application\Audit\AuditWriter;
use App\Models\SyncOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The generic offline-queue replay endpoint (POST /mobile/sync/operations)
 * the Offline Sync batch's ResumableUploadService doc-block explicitly
 * pointed this batch at, per the mobile app's Patch 6 (Resilience &
 * Inclusion) "Laravel synchronization contract": "the mobile client queues
 * DRAFT/MUTATION/UPLOAD operations while offline and replays them once back
 * online". Built on the same IdempotencyGuard the upload endpoints use for
 * the HTTP-level Idempotency-Key header replay; this service adds a SECOND,
 * domain-level dedup layer keyed by the operation's own client-generated
 * UUID (sync_operations.operation_uuid), because the contract explicitly
 * calls for both ("the operation UUID and idempotency key").
 *
 * Allowlist, by design: this endpoint never executes an arbitrary
 * client-supplied route. ALLOWLIST maps an exact "METHOD path" pair to one
 * of a small, explicit set of operation types, each backed by a handler in
 * apply() that re-runs the EXACT SAME validation and domain-service call the
 * live endpoint uses (see AgentClientIntakeService::rules()) — never a
 * shortcut that trusts the queued payload more than a live request would be
 * trusted.
 *
 * Scope note (see this batch's final report): only ONE operation is wired
 * end-to-end here — agent client intake, the operation the Patch 4 and
 * Patch 6 merge guides both call out by name ("Merge the offline-draft
 * branches in claim incident and agent client registration"). Registering
 * more allowlisted operations (e.g. a claim-incident draft from a future
 * Claims batch) is a matter of adding one ALLOWLIST entry and one apply()
 * match arm — never routing through here to an unvetted controller action.
 * Per the merge guide, payments/refunds/withdrawals/underwriting/policy
 * issuance/claim decisions/settlements must NEVER be reachable through this
 * endpoint; none are in ALLOWLIST, and none should ever be added.
 */
final class SyncOperationDispatchService
{
    private const MINIMUM_CLIENT_VERSION = '1.0.0';

    /** @var array<string, string> "METHOD path" (relative, no leading slash, no /api/v1 prefix) => operation type */
    private const ALLOWLIST = [
        'POST mobile/agent/clients' => 'AGENT_CLIENT_INTAKE',
    ];

    public function __construct(
        private readonly AgentClientIntakeService $clients,
        private readonly AuditWriter $audit,
    ) {
    }

    /** @return array{server_time: string, minimum_client_version: string} */
    public function status(): array
    {
        return ['server_time' => now()->toIso8601String(), 'minimum_client_version' => self::MINIMUM_CLIENT_VERSION];
    }

    public function list(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return SyncOperation::where('tenant_id', $tenantId)->where('user_id', $user->id)->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * @param  array{id: string, kind: string, resource: string, resource_id: ?string, method: string, path: string, payload: array<string, mixed>}  $envelope
     * @return array{operation_id: string, status: string, server_version: ?string, synchronized_at: ?string}
     */
    public function dispatch(array $envelope, User $user, string $tenantId): array
    {
        $existing = SyncOperation::where('tenant_id', $tenantId)->where('user_id', $user->id)->where('operation_uuid', $envelope['id'])->first();

        if ($existing) {
            return $this->finish($existing);
        }

        $type = $this->allowlisted($envelope);

        if (! $type) {
            $row = $this->persist($envelope, $user, $tenantId, 'REJECTED', null, 'OPERATION_NOT_ALLOWLISTED');
            $this->audit->record('sync.operation.rejected', 'sync_operation', $row->id, ['operation_uuid' => $envelope['id'], 'reason' => 'OPERATION_NOT_ALLOWLISTED']);

            throw ValidationException::withMessages(['path' => [__('wave12.sync_operation_not_allowlisted')]]);
        }

        try {
            $result = $this->apply($type, $envelope['payload'], $user, $tenantId);
        } catch (ValidationException $e) {
            $status = ($e->status ?? 422) === 409 ? 'CONFLICT' : 'REJECTED';
            $row = $this->persist($envelope, $user, $tenantId, $status, ['errors' => $e->errors()], 'VALIDATION_FAILED');
            $this->audit->record('sync.operation.'.strtolower($status), 'sync_operation', $row->id, ['operation_uuid' => $envelope['id']]);

            throw $e;
        }

        $row = $this->persist($envelope, $user, $tenantId, 'APPLIED', $result, null);
        $this->audit->record('sync.operation.applied', 'sync_operation', $row->id, ['operation_uuid' => $envelope['id'], 'resource' => $envelope['resource']]);

        return $this->outcome($row);
    }

    /**
     * Explicit, user-triggered re-attempt of a previously REJECTED/CONFLICT
     * operation, using the ORIGINALLY queued payload (never a new one — a
     * retry replays what was queued, it does not accept edits). An already
     * APPLIED operation is untouched: retrying it just returns its outcome.
     */
    public function retry(string $operationUuid, User $user, string $tenantId): array
    {
        $row = SyncOperation::where('tenant_id', $tenantId)->where('user_id', $user->id)->where('operation_uuid', $operationUuid)->first();

        if (! $row) {
            throw new ModelNotFoundException;
        }

        if ($row->status === 'APPLIED') {
            return $this->outcome($row);
        }

        $type = self::ALLOWLIST[strtoupper($row->method).' '.ltrim($row->path, '/')] ?? null;

        if (! $type) {
            throw ValidationException::withMessages(['path' => [__('wave12.sync_operation_not_allowlisted')]]);
        }

        try {
            $result = $this->apply($type, $row->payload ?? [], $user, $tenantId);
        } catch (ValidationException $e) {
            $status = ($e->status ?? 422) === 409 ? 'CONFLICT' : 'REJECTED';
            $row->update(['status' => $status, 'response_body' => ['errors' => $e->errors()], 'attempt_count' => $row->attempt_count + 1]);
            $this->audit->record('sync.operation.retried', 'sync_operation', $row->id, ['operation_uuid' => $operationUuid, 'outcome' => $status]);

            throw $e;
        }

        $row->update(['status' => 'APPLIED', 'response_body' => $result, 'error_code' => null, 'attempt_count' => $row->attempt_count + 1, 'synchronized_at' => now()]);
        $this->audit->record('sync.operation.retried', 'sync_operation', $row->id, ['operation_uuid' => $operationUuid, 'outcome' => 'APPLIED']);

        return $this->outcome($row->refresh());
    }

    /** @return array<string, mixed> */
    private function apply(string $type, array $payload, User $user, string $tenantId): array
    {
        return match ($type) {
            'AGENT_CLIENT_INTAKE' => $this->applyAgentClientIntake($payload, $user, $tenantId),
        };
    }

    /** @return array<string, mixed> */
    private function applyAgentClientIntake(array $payload, User $user, string $tenantId): array
    {
        Validator::make($payload, AgentClientIntakeService::rules())->validate();

        return $this->clients->register($payload, $user, $tenantId);
    }

    private function allowlisted(array $envelope): ?string
    {
        return self::ALLOWLIST[strtoupper($envelope['method']).' '.ltrim($envelope['path'], '/')] ?? null;
    }

    private function persist(array $envelope, User $user, string $tenantId, string $status, ?array $responseBody, ?string $errorCode): SyncOperation
    {
        return SyncOperation::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'operation_uuid' => $envelope['id'],
            'kind' => $envelope['kind'],
            'resource' => $envelope['resource'],
            'resource_id' => $envelope['resource_id'] ?? null,
            'method' => $envelope['method'],
            'path' => $envelope['path'],
            'status' => $status,
            'payload' => $envelope['payload'],
            'response_body' => $responseBody,
            'error_code' => $errorCode,
            'attempt_count' => 1,
            'synchronized_at' => $status === 'APPLIED' ? now() : null,
        ]);
    }

    /** @return array{operation_id: string, status: string, server_version: ?string, synchronized_at: ?string} */
    private function outcome(SyncOperation $row): array
    {
        return [
            'operation_id' => $row->operation_uuid,
            'status' => $row->status,
            'server_version' => $row->server_version,
            'synchronized_at' => $row->synchronized_at?->toIso8601String(),
        ];
    }

    /** A repeat POST of an already-processed operation id returns the SAME outcome — success replays the result, failure replays the same rejection, never a silent re-execution. */
    private function finish(SyncOperation $row): array
    {
        if ($row->status === 'APPLIED') {
            return $this->outcome($row);
        }

        $errors = $row->response_body['errors'] ?? ['sync_operation' => [__('wave12.sync_operation_not_allowlisted')]];

        throw ValidationException::withMessages($errors)->status($row->status === 'CONFLICT' ? 409 : 422);
    }
}
