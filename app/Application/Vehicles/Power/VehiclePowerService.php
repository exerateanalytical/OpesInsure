<?php

declare(strict_types=1);

namespace App\Application\Vehicles\Power;

use App\Models\User;
use App\Models\Vehicles\VehicleVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Technical power master (vehicle_power_specs) linked make → model → generation → variant → power specification.
 * Normalizes once to kW (PowerUnits), preserves the source value / unit / provenance, versions every change and keeps
 * vehicle_variants.power_kw / power_hp in sync for the existing pickers. Never touches fiscal power (VPWR-003/006).
 */
final class VehiclePowerService
{
    public const SOURCE_TYPES = ['OFFICIAL_MANUFACTURER_SPECIFICATION', 'TYPE_APPROVAL_OR_HOMOLOGATION', 'OFFICIAL_VEHICLE_DOCUMENT',
        'TRUSTED_TECHNICAL_DATABASE', 'AUTHORIZED_DISTRIBUTOR', 'MANUAL_VERIFIED_ENTRY'];

    public function __construct(private readonly VehiclePowerAudit $audit, private readonly FiscalPowerService $fiscal) {}

    public function record(VehicleVariant $variant, array $d, User $actor): object
    {
        foreach (['fiscal_power_cv', 'fiscal_power', 'cv_fiscal', 'puissance_fiscale', 'puissance_administrative'] as $k) {
            if (array_key_exists($k, $d)) {
                throw ValidationException::withMessages([$k => 'VPWR-003: fiscal power is not technical power; record it through the fiscal-power workflow with an authoritative source.']);
            }
        }
        if (! isset($d['power_source_value'], $d['power_source_unit']) || ! is_numeric($d['power_source_value'])) {
            throw ValidationException::withMessages(['power_source_value' => 'A source power value and unit are required.']);
        }
        $sourceType = isset($d['power_source_type']) ? strtoupper((string) $d['power_source_type']) : null;
        if ($sourceType !== null && ! in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw ValidationException::withMessages(['power_source_type' => 'Unknown technical power source type.']);
        }
        $n = PowerUnits::normalize((float) $d['power_source_value'], (string) $d['power_source_unit']);

        return DB::transaction(function () use ($variant, $d, $actor, $n, $sourceType) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['vehicle_power_specs:'.$variant->id]);
            $version = (int) DB::table('vehicle_power_specs')->where('variant_id', $variant->id)->max('version') + 1;
            $id = (string) Str::uuid();
            $status = $sourceType !== null && filled($d['power_source_reference'] ?? null) ? 'SOURCE_ATTACHED' : 'DRAFT';
            DB::table('vehicle_power_specs')->insert([...$n, 'id' => $id, 'variant_id' => $variant->id, 'version' => $version, 'status' => $status,
                'power_rpm' => $d['power_rpm'] ?? null, 'torque_nm' => $d['torque_nm'] ?? null, 'torque_rpm' => $d['torque_rpm'] ?? null,
                'displacement_cc' => $d['displacement_cc'] ?? $variant->engine_capacity_cc,
                'power_source_type' => $sourceType, 'power_source_name' => $d['power_source_name'] ?? null, 'power_source_reference' => $d['power_source_reference'] ?? null,
                'power_source_url' => $d['power_source_url'] ?? null, 'power_verification_status' => 'UNVERIFIED',
                'effective_from' => $d['effective_from'] ?? now()->toDateString(), 'effective_until' => $d['effective_until'] ?? null,
                'created_by' => $actor->id, 'notes' => $d['notes'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            // Existing picker columns keep working (power_hp there is an integer mechanical hp).
            $variant->forceFill(['power_kw' => round($n['power_kw'], 2), 'power_hp' => (int) round($n['power_hp'])])->save();
            $this->audit->record('vehicle_power_spec', $id, 'technical_power.recorded', null, $status, $actor->id,
                ['variant_id' => $variant->id, 'version' => $version, 'source_value' => $n['power_source_value'], 'source_unit' => $n['power_source_unit'], 'power_kw' => $n['power_kw']]);

            return DB::table('vehicle_power_specs')->where('id', $id)->first();
        });
    }

    /** GET master-data/vehicles/{variant}/power */
    public function show(VehicleVariant $variant): array
    {
        $specs = DB::table('vehicle_power_specs')->where('variant_id', $variant->id)->orderByDesc('version')->get();
        $current = $specs->first();

        return [
            'variant_id' => $variant->id, 'variant_code' => $variant->code, 'model_id' => $variant->model_id, 'generation_id' => $variant->generation_id,
            'technical_power' => $current ? $this->technical($current) : ($variant->power_kw !== null ? ['power_kw' => (float) $variant->power_kw, 'power_hp' => $variant->power_hp, 'power_ps' => null, 'source' => 'vehicle_variants'] : null),
            'technical_power_versions' => $specs->map(fn ($s) => $this->technical($s))->values()->all(),
            'fiscal_power' => $this->fiscal->currentFor($variant->id, null),
            'fiscal_power_versions' => DB::table('vehicle_fiscal_power_records')->where('variant_id', $variant->id)->whereNull('registration_number')->orderByDesc('version')->get()->all(),
        ];
    }

    /** GET master-data/vehicles/power/search */
    public function search(array $f, int $limit = 50): array
    {
        $q = DB::table('vehicle_variants as v')->join('vehicle_models as m', 'm.id', '=', 'v.model_id')
            ->leftJoin('vehicle_power_specs as s', fn ($j) => $j->on('s.variant_id', '=', 'v.id')->whereRaw('s.version = (SELECT max(version) FROM vehicle_power_specs x WHERE x.variant_id = v.id)'))
            ->leftJoin('vehicle_fiscal_power_records as f', fn ($j) => $j->on('f.variant_id', '=', 'v.id')->whereNull('f.registration_number')->where('f.review_state', 'VERIFIED'))
            ->select(['v.id as variant_id', 'v.code as variant_code', 'v.name as variant_name', 'm.code as model_code', 'm.make_id', 'v.model_id', 'v.generation_id',
                's.power_kw', 's.power_hp', 's.power_ps', 's.power_source_unit', 'f.fiscal_power_cv', 'f.fiscal_power_band_code', 'f.verification_status as fiscal_power_verification_status']);
        foreach (['make_id' => 'm.make_id', 'model_id' => 'v.model_id', 'generation_id' => 'v.generation_id', 'variant_id' => 'v.id', 'fiscal_power_cv' => 'f.fiscal_power_cv',
            'fiscal_power_band_code' => 'f.fiscal_power_band_code', 'verification_status' => 'f.verification_status'] as $key => $col) {
            if (filled($f[$key] ?? null)) {
                $q->where($col, $f[$key]);
            }
        }
        if (filled($f['q'] ?? null)) {
            $q->where(fn ($w) => $w->where('v.name', 'ilike', '%'.$f['q'].'%')->orWhere('v.code', 'ilike', '%'.$f['q'].'%'));
        }
        foreach (['min_kw' => '>=', 'max_kw' => '<='] as $key => $op) {
            if (is_numeric($f[$key] ?? null)) {
                $q->where('s.power_kw', $op, (float) $f[$key]);
            }
        }

        return $q->orderBy('v.code')->limit(min(max($limit, 1), 200))->get()->all();
    }

    private function technical(object $s): array
    {
        return ['id' => $s->id, 'version' => (int) $s->version, 'status' => $s->status,
            'power_kw' => $s->power_kw !== null ? (float) $s->power_kw : null, 'power_hp' => $s->power_hp !== null ? (float) $s->power_hp : null,
            'power_ps' => $s->power_ps !== null ? (float) $s->power_ps : null,
            'display' => ['kw' => PowerUnits::display($s->power_kw !== null ? (float) $s->power_kw : null), 'hp' => PowerUnits::display($s->power_hp !== null ? (float) $s->power_hp : null),
                'ps' => PowerUnits::display($s->power_ps !== null ? (float) $s->power_ps : null)],
            'source' => ['value' => $s->power_source_value !== null ? (float) $s->power_source_value : null, 'unit' => $s->power_source_unit, 'type' => $s->power_source_type,
                'name' => $s->power_source_name, 'reference' => $s->power_source_reference, 'url' => $s->power_source_url],
            'conversion_method' => $s->conversion_method, 'power_verification_status' => $s->power_verification_status,
            'effective_from' => $s->effective_from, 'effective_until' => $s->effective_until];
    }
}
