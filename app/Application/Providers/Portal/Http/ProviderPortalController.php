<?php

declare(strict_types=1);

namespace App\Application\Providers\Portal\Http;

use App\Application\Providers\Portal\ProviderPortalService;
use App\Application\Providers\Portal\ProviderScope;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-PRV-003 — provider portal API (/v1/provider-portal/…): read-only, scoped to the caller's provider by ProviderScope. */
final class ProviderPortalController
{
    public function __construct(private readonly ProviderPortalService $svc, private readonly TenantContext $tenant) {}

    public function profile(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->profile(ProviderScope::of($r))]);
    }

    public function facilities(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->facilities(ProviderScope::of($r))]);
    }

    public function services(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->services(ProviderScope::of($r))]);
    }

    public function memberships(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->memberships($this->tenant->id(), ProviderScope::of($r))]);
    }

    public function contracts(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->contracts($this->tenant->id(), ProviderScope::of($r))]);
    }

    public function tariffs(Request $r): JsonResponse
    {
        $d = $r->validate(['contract_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->svc->tariffs($this->tenant->id(), ProviderScope::of($r), $d['contract_id'] ?? null)]);
    }

    public function assignments(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->assignments($this->tenant->id(), ProviderScope::of($r), $this->status($r))]);
    }

    public function preauthorizations(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->preauthorizations($this->tenant->id(), ProviderScope::of($r), $this->status($r))]);
    }

    public function claims(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->providerClaims($this->tenant->id(), ProviderScope::of($r), $this->status($r))]);
    }

    public function statement(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->statement($this->tenant->id(), ProviderScope::of($r), $this->status($r))]);
    }

    public function payments(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->payments($this->tenant->id(), ProviderScope::of($r))]);
    }

    private function status(Request $r): ?string
    {
        return $r->validate(['status' => 'nullable|string|max:40'])['status'] ?? null;
    }
}
