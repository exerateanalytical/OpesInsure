<?php

declare(strict_types=1);

namespace App\Application\Claims\Execution\Http;

use App\Application\Claims\Execution\ClaimCarrierSignatureVerifier;
use App\Application\Claims\Execution\ClaimExecutionService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** REQ-CLM-011 — claims execution mode, carrier submission and carrier answers (signed API / maker-checker manual entry). */
final class ClaimExecutionController
{
    public function show(Request $r, string $id, ClaimExecutionService $s): JsonResponse
    {
        $claim = $this->claim($id);

        return response()->json(['data' => $s->describe($claim, $r->user()), 'meta' => [
            'messages' => DB::table('carrier_exchange_messages')->where('claim_id', $claim->id)->orderBy('created_at')->get(),
            'manual_entries' => DB::table('claim_carrier_manual_entries')->where('claim_id', $claim->id)->orderBy('created_at')->get(),
        ]]);
    }

    public function submit(Request $r, string $id, ClaimExecutionService $s): JsonResponse
    {
        $d = $r->validate(['idempotency_key' => 'required|string|min:16|max:80', 'portal_reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $s->submit($this->claim($id), $d['idempotency_key'], $r->user(), $d)], 202);
    }

    public function inbound(Request $r, string $id, ClaimExecutionService $s): JsonResponse
    {
        $out = $s->receiveSigned($this->claim($id), $r->getContent(), (string) $r->header('X-Carrier-Key-Id'), (int) $r->header('X-Carrier-Timestamp'), (string) $r->header('X-Carrier-Signature'));

        return response()->json(['data' => $out['message'], 'meta' => ['replayed' => $out['replayed']]], $out['replayed'] ? 200 : 201);
    }

    public function propose(Request $r, string $id, ClaimExecutionService $s): JsonResponse
    {
        return response()->json(['data' => $s->proposeManualEntry($this->claim($id), $r->all(), $r->user())], 201);
    }

    public function approve(Request $r, string $id, string $entry, ClaimExecutionService $s): JsonResponse
    {
        return response()->json(['data' => $s->reviewManualEntry($this->claim($id), $entry, true, $r->user())]);
    }

    public function reject(Request $r, string $id, string $entry, ClaimExecutionService $s): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $s->reviewManualEntry($this->claim($id), $entry, false, $r->user(), $d['reason'])]);
    }

    public function registerKey(Request $r, string $carrier, ClaimCarrierSignatureVerifier $keys): JsonResponse
    {
        abort_unless(DB::table('carriers')->where('id', $carrier)->exists(), 404);
        $d = $r->validate(['key_id' => 'nullable|string|min:6|max:64|regex:/^[A-Za-z0-9_\-]+$/']);

        return response()->json(['data' => $keys->register($carrier, $r->user(), $d['key_id'] ?? null)], 201);
    }

    public function revokeKey(string $carrier, string $key, ClaimCarrierSignatureVerifier $keys): JsonResponse
    {
        $keys->revoke($carrier, $key);

        return response()->json(['data' => ['key_id' => $key, 'status' => 'REVOKED']]);
    }

    private function claim(string $id): Claim
    {
        return Claim::where(['id' => $id, 'tenant_id' => app(TenantContext::class)->id()])->firstOrFail();
    }
}
