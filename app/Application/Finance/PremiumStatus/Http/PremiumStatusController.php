<?php

declare(strict_types=1);

namespace App\Application\Finance\PremiumStatus\Http;

use App\Application\Finance\PremiumStatus\PremiumComponentService;
use App\Application\Finance\PremiumStatus\PremiumStatusReadModel;
use App\Application\Identity\OwnershipScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-PAY-005 — premium components and the derived premium status of a policy. */
final class PremiumStatusController
{
    public function __construct(private readonly PremiumComponentService $components, private readonly PremiumStatusReadModel $status) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private function scopedPolicy(string $policy): Policy
    {
        $p = $this->components->policy($policy, $this->tenant());
        $user = request()->user();
        if ($user !== null) {
            app(OwnershipScope::class)->assertOwnParty($user, $p->party_id);
        }

        return $p;
    }

    public function show(string $policy): JsonResponse
    {
        return response()->json(['data' => $this->status->forPolicy($this->scopedPolicy($policy))]);
    }

    public function record(Request $r, string $policy): JsonResponse
    {
        $p = $this->components->policy($policy, $this->tenant());
        $d = $r->validate([
            'from_terms' => 'sometimes|boolean',
            'components' => 'required_without:from_terms|array|max:50',
            'components.*.line_key' => 'required|string|max:64', 'components.*.component' => ['required', Rule::in(PremiumComponentService::COMPONENTS)],
            'components.*.amount_minor' => 'required|integer', 'components.*.due_at' => 'nullable|date',
            'components.*.financial_obligation_id' => 'nullable|uuid', 'components.*.snapshot' => 'sometimes|array',
        ]);
        if (! empty($d['from_terms'])) {
            $this->components->captureFromTerms($p, $r->user());
        } else {
            $this->components->record($p, array_map(fn ($c) => ['amount_minor' => (int) $c['amount_minor']] + $c, $d['components']), 'MANUAL', $r->user());
        }

        return response()->json(['data' => $this->status->forPolicy($p)], 201);
    }

    public function close(Request $r, string $component): JsonResponse
    {
        $d = $r->validate(['closure' => ['required', Rule::in(PremiumComponentService::CLOSURES)], 'reason' => 'required|string|max:255']);
        $c = $this->components->close($component, $this->tenant(), $d['closure'], $d['reason'], $r->user());

        return response()->json(['data' => $this->status->forPolicy(Policy::findOrFail($c->policy_id))]);
    }
}
