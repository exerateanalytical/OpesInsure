<?php

declare(strict_types=1);

namespace App\Application\Claims;

use App\Application\Audit\AuditWriter;
use App\Models\Claim;
use App\Models\User;

/**
 * Saves the incident details of a claim the user owns (loss_details.incident).
 * Shared by PUT /mobile/claims/{id}/incident and the offline sync replay
 * (SyncOperationDispatchService CUSTOMER_CLAIM_INCIDENT) so both apply the
 * exact same validation and ownership rules.
 */
final class ClaimIncidentService
{
    public const DEFAULTS = ['incident_type' => 'OTHER', 'police_report_number' => null, 'latitude' => null, 'longitude' => null, 'injuries_reported' => false, 'vehicle_drivable' => true, 'towing_required' => false, 'declaration_confirmed' => false];

    public function __construct(private MobileClaimService $claims, private AuditWriter $audit) {}

    /** @return array<string, array<int, string>|string> */
    public static function rules(): array
    {
        return [
            'incident_type' => 'sometimes|string|max:64', 'police_report_number' => 'nullable|string|max:120', 'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'injuries_reported' => 'sometimes|boolean', 'vehicle_drivable' => 'sometimes|boolean', 'towing_required' => 'sometimes|boolean', 'declaration_confirmed' => 'sometimes|boolean',
        ];
    }

    /** @param array<string, mixed> $data already validated against rules() */
    public function save(string $claimId, array $data, User $user, string $tenantId): Claim
    {
        $claim = $this->claims->owned($claimId, $user, $tenantId);
        $data = array_intersect_key($data, self::DEFAULTS);
        $details = $claim->loss_details ?? [];
        $details['incident'] = array_merge(self::DEFAULTS, $details['incident'] ?? [], $data);
        $claim->update(['loss_details' => $details, 'version' => $claim->version + 1]);
        if (! empty($data['declaration_confirmed'])) {
            $this->audit->record('claim.declaration.confirmed', 'claim', $claim->id, []);
        }

        return $claim->refresh();
    }

    /** @return array<string, mixed> */
    public static function present(Claim $claim): array
    {
        return ['claim_id' => $claim->id] + array_merge(self::DEFAULTS, ($claim->loss_details ?? [])['incident'] ?? []);
    }
}
