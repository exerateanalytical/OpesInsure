<?php

declare(strict_types=1);

namespace App\Application\Policies\Http;

use App\Application\Policies\Endorsements\ServiceRequestIntake;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * REQ-DUP-014 staff triage of customer service requests written by ServiceRequestIntake. Conversion is not a
 * separate action: staff raise the endorsement through POST policies/{policy}/transactions with
 * service_request_id, which moves the request to CONVERTED (EndorsementService::attach).
 */
final class ServiceRequestTriageController
{
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'status' => ['sometimes', Rule::in(['REQUESTED', 'CONVERTED'])], 'type' => ['sometimes', Rule::in(ServiceRequestIntake::TYPES)],
            'policy_id' => 'sometimes|uuid',
        ]);
        $rows = DB::table('policy_transactions')->where('tenant_id', app(TenantContext::class)->id())
            ->whereIn('type', ServiceRequestIntake::TYPES)->where('status', $d['status'] ?? 'REQUESTED')
            ->when(isset($d['type']), fn ($q) => $q->where('type', $d['type']))
            ->when(isset($d['policy_id']), fn ($q) => $q->where('policy_id', $d['policy_id']))
            ->orderBy('created_at')->limit(200)
            ->get(['id', 'policy_id', 'type', 'status', 'transaction_number', 'endorsement_type', 'channel', 'notes', 'requested_by', 'created_at']);

        return response()->json(['data' => $rows]);
    }
}
