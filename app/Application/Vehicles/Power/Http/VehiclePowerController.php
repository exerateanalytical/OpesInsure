<?php

declare(strict_types=1);

namespace App\Application\Vehicles\Power\Http;

use App\Application\Vehicles\Power\FiscalPowerBands;
use App\Application\Vehicles\Power\FiscalPowerService;
use App\Application\Vehicles\Power\VehiclePowerService;
use App\Application\Vehicles\Power\VehicleStampDutyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Vehicles\VehicleVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Agent V1 — Vehicle Power & Fiscal Power master API (spec api_contract, /api/v1 style). */
final class VehiclePowerController
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly VehiclePowerService $power,
        private readonly FiscalPowerService $fiscal,
        private readonly FiscalPowerBands $bands,
        private readonly VehicleStampDutyService $duty,
    ) {}

    public function show(string $variant): JsonResponse
    {
        return response()->json(['data' => $this->power->show(VehicleVariant::findOrFail($variant))]);
    }

    public function search(Request $r): JsonResponse
    {
        $f = $r->validate(['q' => 'nullable|string|max:120', 'make_id' => 'nullable|uuid', 'model_id' => 'nullable|uuid', 'generation_id' => 'nullable|uuid', 'variant_id' => 'nullable|uuid',
            'fiscal_power_cv' => 'nullable|integer|min:1', 'fiscal_power_band_code' => 'nullable|string|max:16', 'verification_status' => 'nullable|string|max:40',
            'min_kw' => 'nullable|numeric', 'max_kw' => 'nullable|numeric', 'limit' => 'nullable|integer|min:1|max:200']);

        return response()->json(['data' => $this->power->search($f, (int) ($f['limit'] ?? 50))]);
    }

    public function bands(): JsonResponse
    {
        return response()->json(['data' => $this->bands->all()]);
    }

    public function rates(Request $r): JsonResponse
    {
        $f = $r->validate(['at' => 'nullable|date', 'all' => 'nullable|boolean']);

        return response()->json(['data' => $this->duty->schedules($f['at'] ?? null, (bool) ($f['all'] ?? false))]);
    }

    public function recordPower(Request $r, string $variant): JsonResponse
    {
        $d = $r->validate(['power_source_value' => 'required|numeric', 'power_source_unit' => 'required|string|max:16', 'power_source_type' => 'nullable|string|max:48',
            'power_source_name' => 'nullable|string|max:191', 'power_source_reference' => 'nullable|string|max:191', 'power_source_url' => 'nullable|url|max:500',
            'power_rpm' => 'nullable|integer|min:1', 'torque_nm' => 'nullable|integer|min:1', 'torque_rpm' => 'nullable|integer|min:1', 'displacement_cc' => 'nullable|integer|min:1',
            'effective_from' => 'nullable|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from', 'notes' => 'nullable|string|max:2000']);
        // VPWR-003: a fiscal CV key on the technical endpoint is refused by the service (never silently dropped).
        $d += array_intersect_key($r->all(), array_flip(['fiscal_power_cv', 'fiscal_power', 'cv_fiscal', 'puissance_fiscale', 'puissance_administrative']));

        return response()->json(['data' => $this->power->record(VehicleVariant::findOrFail($variant), $d, $r->user())], 201);
    }

    /** Maker: capture fiscal power for a variant (route) or a registration / VIN (body). */
    public function submitFiscal(Request $r, ?string $variant = null): JsonResponse
    {
        $d = $this->fiscalInput($r);
        if ($variant !== null) {
            $d['variant_id'] = VehicleVariant::findOrFail($variant)->id;
        }

        return response()->json(['data' => $this->fiscal->submit($d, $r->user(), $this->tenant->id())], 201);
    }

    /** Maker: capture fiscal power for a registration / VIN (vehicle-specific CIVIC / registration document). */
    public function submitRecord(Request $r): JsonResponse
    {
        return $this->submitFiscal($r);
    }

    public function attachSource(Request $r, string $record): JsonResponse
    {
        return response()->json(['data' => $this->fiscal->attachSource($record, $this->fiscalInput($r, false), $r->user())]);
    }

    public function verify(Request $r, string $variant): JsonResponse
    {
        $d = $r->validate(['record_id' => 'required|uuid', 'notes' => 'nullable|string|max:2000']);
        $rec = $this->fiscal->find($d['record_id']);
        abort_unless($rec->variant_id === $variant, 404);

        return response()->json(['data' => $this->fiscal->verify($rec->id, $r->user(), $d['notes'] ?? null, $this->tenant->id())]);
    }

    public function verifyRecord(Request $r, string $record): JsonResponse
    {
        $d = $r->validate(['notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->fiscal->verify($record, $r->user(), $d['notes'] ?? null, $this->tenant->id())]);
    }

    public function reject(Request $r, string $record): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->fiscal->reject($record, $r->user(), $d['reason'])]);
    }

    public function conflict(Request $r, string $variant): JsonResponse
    {
        $d = $this->fiscalInput($r);
        $d['variant_id'] = VehicleVariant::findOrFail($variant)->id;

        return response()->json(['data' => $this->fiscal->reportConflict($d, $r->user(), $this->tenant->id())], 201);
    }

    public function resolveConflict(Request $r, string $conflict): JsonResponse
    {
        $d = $r->validate(['accept_challenger' => 'required|boolean', 'reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->fiscal->resolveConflict($conflict, (bool) $d['accept_challenger'], $r->user(), $d['reason'])]);
    }

    public function scheduleVersion(Request $r): JsonResponse
    {
        $d = $r->validate(['schedule_code' => ['required', Rule::in(VehicleStampDutyService::SCHEDULES)], 'rates_xaf' => 'required|array', 'rates_xaf.*' => 'required|integer|min:0',
            'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from', 'legal_reference' => 'required|string|max:255',
            'label_fr' => 'nullable|string|max:191', 'source_status' => 'nullable|string|max:48', 'source_ids' => 'nullable|array', 'source_ids.*' => 'string|max:64']);

        return response()->json(['data' => $this->duty->createVersion($d, $r->user())], 201);
    }

    public function approveSchedule(Request $r, string $schedule): JsonResponse
    {
        return response()->json(['data' => $this->duty->approve($schedule, $r->user())]);
    }

    public function recordLicence(Request $r): JsonResponse
    {
        $d = $r->validate(['registration_number' => 'required|string|max:40', 'licence_number' => 'required|string|max:80', 'licence_type' => 'nullable|string|max:48',
            'issuing_authority' => 'nullable|string|max:191', 'valid_from' => 'required|date', 'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'source_document_id' => 'nullable|uuid', 'notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->duty->recordLicence($this->tenant->id(), $d, $r->user())], 201);
    }

    public function decideLicence(Request $r, string $licence): JsonResponse
    {
        $d = $r->validate(['status' => ['required', Rule::in(['VALID', 'REJECTED', 'EXPIRED', 'SUSPENDED', 'REVOKED'])], 'notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->duty->decideLicence($this->tenant->id(), $licence, $d['status'], $r->user(), $d['notes'] ?? null)]);
    }

    private function fiscalInput(Request $r, bool $cvRequired = true): array
    {
        $d = $r->validate(['fiscal_power_cv' => ($cvRequired ? 'required' : 'nullable').'|integer', 'fiscal_power_unit' => 'nullable|string|max:16',
            'registration_number' => 'nullable|string|max:40', 'vin' => 'nullable|string|max:40', 'variant_id' => 'nullable|uuid',
            'source_type' => 'nullable|string|max:40', 'source_reference' => 'nullable|string|max:191', 'source_document_id' => 'nullable|uuid', 'source_url' => 'nullable|url|max:500',
            'effective_from' => 'nullable|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from', 'notes' => 'nullable|string|max:2000']);
        // VPWR-003 / 006: derivation inputs are passed through so the service refuses them explicitly.
        foreach (['power_ps', 'power_hp', 'power_kw', 'displacement_cc', 'engine_capacity_cc', 'cylinder_count', 'derived_from', 'formula'] as $k) {
            if ($r->has($k)) {
                $d[$k] = $r->input($k);
            }
        }

        return $d;
    }
}
