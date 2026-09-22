<?php
declare(strict_types=1);
namespace App\Application\Integrations;

use App\Application\Audit\AuditWriter;
use App\Models\{ExternalRecordMapping,IntegrationClient};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place allowed to say "this OpesInsure record is that partner record."
 * External IDs never replace internal UUIDs — they are only ever looked up
 * through this mapping. See docs/design/... for the conflict rules this
 * enforces: OpesInsure never silently accepts a partner's version of a field
 * it does not own (source_of_truth = OPESINSURE) without a conflict record.
 */
final class ExternalRecordMappingService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function map(IntegrationClient $client, string $recordType, string $externalRecordId, string $opesinsureRecordId, array $attrs = []): ExternalRecordMapping
    {
        return DB::transaction(function () use ($client, $recordType, $externalRecordId, $opesinsureRecordId, $attrs) {
            $existing = ExternalRecordMapping::where([
                'integration_client_id' => $client->id,
                'record_type' => $recordType,
                'external_record_id' => $externalRecordId,
            ])->lockForUpdate()->first();

            if ($existing && $existing->opesinsure_record_id !== $opesinsureRecordId) {
                throw ValidationException::withMessages([
                    'external_record_id' => "This external record is already mapped to a different OpesInsure record ({$existing->opesinsure_record_id}).",
                ]);
            }

            $mapping = ExternalRecordMapping::updateOrCreate(
                ['integration_client_id' => $client->id, 'record_type' => $recordType, 'external_record_id' => $externalRecordId],
                [
                    'tenant_id' => $client->partner?->tenant_id,
                    'opesinsure_record_id' => $opesinsureRecordId,
                    'source_of_truth' => $attrs['source_of_truth'] ?? 'OPESINSURE',
                    'external_version' => $attrs['external_version'] ?? null,
                    'opesinsure_version' => $attrs['opesinsure_version'] ?? 1,
                    'last_external_modified_at' => $attrs['last_external_modified_at'] ?? null,
                    'last_synchronized_at' => now(),
                    'synchronization_status' => 'SYNCED',
                    'conflict_status' => null,
                    'metadata' => $attrs['metadata'] ?? [],
                ],
            );

            $this->audit->record('integration.record.mapped', $recordType, $opesinsureRecordId, [
                'integration_client_id' => $client->id,
                'external_record_id' => $externalRecordId,
            ]);

            return $mapping;
        });
    }

    /**
     * Records that the external side changed a field OpesInsure considers
     * itself authoritative for. This never overwrites — it raises a queued
     * conflict for human review, per the plan's "no uncontrolled last-write-
     * wins" rule.
     */
    public function flagConflict(ExternalRecordMapping $mapping, string $reason, array $metadata = []): ExternalRecordMapping
    {
        $mapping->update([
            'synchronization_status' => 'CONFLICT',
            'conflict_status' => $reason,
            'metadata' => [...$mapping->metadata, 'conflict' => $metadata],
        ]);
        $this->audit->record('integration.record.conflict_flagged', $mapping->record_type, $mapping->opesinsure_record_id, $metadata, $reason);

        return $mapping->refresh();
    }

    public function findByExternalId(IntegrationClient $client, string $recordType, string $externalRecordId): ?ExternalRecordMapping
    {
        return ExternalRecordMapping::where([
            'integration_client_id' => $client->id,
            'record_type' => $recordType,
            'external_record_id' => $externalRecordId,
        ])->first();
    }
}
