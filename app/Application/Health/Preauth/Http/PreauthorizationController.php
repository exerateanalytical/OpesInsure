<?php

declare(strict_types=1);

namespace App\Application\Health\Preauth\Http;

use App\Application\Health\Preauth\PreauthLifecycle;
use App\Application\Health\Preauth\PreauthorizationService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Agent E3: REQ-HLT-002 health preauthorization / guarantee of payment API. */
final class PreauthorizationController
{
    public function __construct(private TenantContext $tenant, private PreauthorizationService $service) {}

    public function index(Request $r): JsonResponse
    {
        $f = $r->validate(['status' => 'nullable|string|max:24', 'provider_id' => 'nullable|uuid', 'policy_id' => 'nullable|uuid', 'request_type' => 'nullable|string|max:16']);

        return response()->json(['data' => $this->service->list($this->tenant->id(), $f)]);
    }

    public function store(Request $r): JsonResponse
    {
        $type = (string) $r->input('request_type');
        $rules = [
            'request_type' => ['required', Rule::in(PreauthLifecycle::TYPES)], 'policy_id' => 'required|uuid', 'member_ref' => 'nullable|string|max:120',
            'provider_id' => 'required|uuid', 'facility_id' => 'nullable|uuid', 'contract_id' => 'nullable|uuid', 'clinical_notes' => 'nullable|string|max:5000',
            'details' => 'required|array', 'lines' => 'required|array|min:1|max:100',
            'lines.*.service_code' => 'required_without:lines.*.provider_code|nullable|string|max:64', 'lines.*.provider_code' => 'nullable|string|max:64',
            'lines.*.quantity' => 'required|numeric|gt:0|max:99999', 'lines.*.unit_price_minor' => 'nullable|integer|min:0',
        ];
        foreach (PreauthLifecycle::TYPE_FIELDS[$type] ?? [] as $field => [$required, $rule]) {
            $rules['details.'.$field] = ($required ? 'required|' : 'nullable|').$rule;
        }
        $d = $r->validate($rules);
        $d['details'] = array_intersect_key($d['details'], PreauthLifecycle::TYPE_FIELDS[$type]);

        return $this->ok($this->service->request($this->tenant->id(), $d, $r->user()), 201);
    }

    public function show(string $preauth): JsonResponse
    {
        return response()->json(['data' => $this->service->present($this->service->find($this->tenant->id(), $preauth), true)]);
    }

    public function requestInfo(Request $r, string $preauth): JsonResponse
    {
        $d = $r->validate(['question' => 'required|string|max:2000']);

        return $this->ok($this->service->requestInfo($this->tenant->id(), $preauth, $d['question'], $r->user()));
    }

    public function provideInfo(Request $r, string $preauth): JsonResponse
    {
        $d = $r->validate(['answer' => 'required|string|max:5000']);

        return $this->ok($this->service->provideInfo($this->tenant->id(), $preauth, $d['answer'], $r->user()));
    }

    public function propose(Request $r, string $preauth): JsonResponse
    {
        $d = $r->validate([
            'decision' => ['required', Rule::in(PreauthLifecycle::DECISIONS)], 'reason_code' => 'nullable|string|max:64', 'notes' => 'nullable|string|max:5000',
            'valid_from' => 'nullable|date', 'valid_until' => 'nullable|date', 'lines' => 'nullable|array|max:100', 'lines.*.line_id' => 'required|uuid',
            'lines.*.approved_quantity' => 'nullable|numeric|min:0', 'lines.*.approved_amount_minor' => 'nullable|integer|min:0', 'lines.*.decline_reason' => 'nullable|string|max:255',
        ]);

        return $this->ok($this->service->propose($this->tenant->id(), $preauth, $d, $r->user()));
    }

    public function returnProposal(Request $r, string $preauth): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return $this->ok($this->service->returnProposal($this->tenant->id(), $preauth, $d['reason'], $r->user()));
    }

    public function decide(Request $r, string $preauth): JsonResponse
    {
        return $this->ok($this->service->decide($this->tenant->id(), $preauth, $r->user()));
    }

    public function admit(Request $r, string $preauth): JsonResponse
    {
        $d = $r->validate(['admitted_on' => 'nullable|date']);

        return $this->ok($this->service->admit($this->tenant->id(), $preauth, $d['admitted_on'] ?? null, $r->user()));
    }

    public function discharge(Request $r, string $preauth): JsonResponse
    {
        $d = $r->validate(['discharged_on' => 'nullable|date']);

        return $this->ok($this->service->discharge($this->tenant->id(), $preauth, $d['discharged_on'] ?? null, $r->user()));
    }

    public function cancel(Request $r, string $preauth): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return $this->ok($this->service->cancel($this->tenant->id(), $preauth, $d['reason'], $r->user()));
    }

    public function requestExtension(Request $r, string $preauth): JsonResponse
    {
        $d = $r->validate([
            'requested_until' => 'required|date', 'reason' => 'required|string|max:2000', 'lines' => 'nullable|array|max:50',
            'lines.*.service_code' => 'required_without:lines.*.provider_code|nullable|string|max:64', 'lines.*.provider_code' => 'nullable|string|max:64',
            'lines.*.quantity' => 'required|numeric|gt:0|max:99999', 'lines.*.unit_price_minor' => 'nullable|integer|min:0',
        ]);

        return response()->json(['data' => $this->service->requestExtension($this->tenant->id(), $preauth, $d, $r->user())], 201);
    }

    public function proposeExtension(Request $r, string $preauth, string $extension): JsonResponse
    {
        $d = $r->validate([
            'decision' => ['required', Rule::in(PreauthLifecycle::DECISIONS)], 'approved_until' => 'nullable|date', 'reason_code' => 'nullable|string|max:64',
            'lines' => 'nullable|array|max:50', 'lines.*.line_id' => 'required|uuid', 'lines.*.approved_quantity' => 'nullable|numeric|min:0',
            'lines.*.approved_amount_minor' => 'nullable|integer|min:0', 'lines.*.decline_reason' => 'nullable|string|max:255',
        ]);

        return response()->json(['data' => $this->service->proposeExtension($this->tenant->id(), $preauth, $extension, $d, $r->user())]);
    }

    public function decideExtension(Request $r, string $preauth, string $extension): JsonResponse
    {
        return response()->json(['data' => $this->service->decideExtension($this->tenant->id(), $preauth, $extension, $r->user())]);
    }

    private function ok(object $pa, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->service->present($pa)], $status);
    }
}
