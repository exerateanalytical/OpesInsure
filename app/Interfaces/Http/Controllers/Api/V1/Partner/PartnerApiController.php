<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Partner;

use App\Application\Integrations\ExternalRecordMappingService;
use App\Models\IntegrationClient;
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Validation\ValidationException;

/**
 * The actual partner-facing surface for this batch: proves the
 * AuthenticateIntegrationClient chain works end to end, and lets a
 * connected partner establish external-record mappings. Business
 * endpoints (submit a customer, submit an order...) are later batches —
 * see docs/design/ROUTE_TO_SCREEN_REGISTER.md.
 */
final class PartnerApiController
{
    public function whoAmI(Request $r): JsonResponse
    {
        /** @var IntegrationClient $client */
        $client = $r->attributes->get('integration_client');

        return response()->json(['data' => [
            'client_name' => $client->name,
            'environment' => $client->environment,
            'scopes' => $client->scopes,
            'partner_id' => $client->partner_id,
        ]]);
    }

    public function mapRecord(Request $r, ExternalRecordMappingService $service): JsonResponse
    {
        $client = $r->attributes->get('integration_client');
        $d = $r->validate([
            'record_type' => 'required|string|max:64',
            'external_record_id' => 'required|string|max:190',
            'opesinsure_record_id' => 'required|uuid',
            'source_of_truth' => 'sometimes|in:OPESINSURE,PARTNER',
            'external_version' => 'sometimes|string|max:64',
        ]);

        try {
            $mapping = $service->map($client, $d['record_type'], $d['external_record_id'], $d['opesinsure_record_id'], $d);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'mapping_conflict', 'message' => $e->validator->errors()->first()], 409);
        }

        return response()->json(['data' => [
            'id' => $mapping->id,
            'record_type' => $mapping->record_type,
            'external_record_id' => $mapping->external_record_id,
            'opesinsure_record_id' => $mapping->opesinsure_record_id,
            'synchronization_status' => $mapping->synchronization_status,
        ]], 201);
    }

    public function showMapping(Request $r, string $recordType, string $externalRecordId, ExternalRecordMappingService $service): JsonResponse
    {
        $client = $r->attributes->get('integration_client');
        $mapping = $service->findByExternalId($client, $recordType, $externalRecordId);

        abort_if($mapping === null, 404);

        return response()->json(['data' => [
            'record_type' => $mapping->record_type,
            'external_record_id' => $mapping->external_record_id,
            'opesinsure_record_id' => $mapping->opesinsure_record_id,
            'synchronization_status' => $mapping->synchronization_status,
            'last_synchronized_at' => $mapping->last_synchronized_at,
        ]]);
    }
}
