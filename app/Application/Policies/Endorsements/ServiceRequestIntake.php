<?php

declare(strict_types=1);

namespace App\Application\Policies\Endorsements;

use App\Application\Audit\AuditWriter;
use App\Models\Policy;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-DUP-014 — the one customer service-request intake. Canonical route: POST policies/{policy}/service-requests;
 * POST mobile/policy-service-requests is a deprecated alias onto the same controller action and this service.
 * Writes a REQUESTED policy_transactions row (the row staff triage into an endorsement / cancellation via
 * PolicyServicingService with service_request_id). Idempotent per (tenant, Idempotency-Key).
 */
final class ServiceRequestIntake
{
    public const TYPES = ['ENDORSEMENT', 'CANCELLATION_REVIEW', 'ADDRESS_CHANGE', 'VEHICLE_CHANGE', 'BENEFICIARY_CHANGE', 'DOCUMENT_REISSUE'];

    public function __construct(private readonly AuditWriter $audit) {}

    public function submit(Policy $policy, string $type, string $reason, User $customer, ?string $idempotencyKey, string $channel = 'MOBILE'): string
    {
        if ($idempotencyKey && ($existing = DB::table('policy_transactions')->where('tenant_id', $policy->tenant_id)->where('idempotency_key', $idempotencyKey)->value('id'))) {
            return $existing;
        }
        $key = $idempotencyKey ?: (string) Str::uuid();
        $id = (string) Str::uuid();
        DB::transaction(function () use ($policy, $type, $reason, $customer, $key, $id, $channel) {
            DB::table('policy_transactions')->insert([
                'id' => $id, 'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id, 'type' => $type, 'status' => 'REQUESTED',
                'transaction_number' => 'PTX-'.now()->format('Ym').'-'.strtoupper(Str::random(8)), 'effective_at' => now(),
                'requested_changes' => json_encode(['reason' => $reason, 'channel' => $channel, 'idempotency_key' => $key]),
                'terms_before' => json_encode($policy->terms_snapshot), 'currency' => $policy->currency ?? 'XAF', 'reason_code' => $type,
                'notes' => $reason, 'requested_by' => $customer->id, 'channel' => $channel, 'idempotency_key' => $key,
                'endorsement_type' => in_array($type, ['ADDRESS_CHANGE', 'VEHICLE_CHANGE', 'BENEFICIARY_CHANGE'], true) ? $type : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('policy_transaction_events')->insert(['id' => (string) Str::uuid(), 'policy_transaction_id' => $id, 'from_status' => null, 'to_status' => 'REQUESTED', 'reason_code' => 'CUSTOMER_REQUEST', 'actor_id' => $customer->id, 'metadata' => json_encode(['message' => $reason]), 'occurred_at' => now()]);
            $this->audit->record('policy.service.requested', 'policy_transaction', $id, ['type' => $type, 'channel' => $channel]);
            UserNotification::notify($customer, 'SERVICE_REQUEST', 'Request received', 'We received your '.strtolower(str_replace('_', ' ', $type)).' request for policy '.($policy->policy_number ?? '').'. Our team will review it within 2 business days.', 'INFO', "/services/$id", $policy->tenant_id);
        });

        return $id;
    }
}
