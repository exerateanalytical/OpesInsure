<?php

declare(strict_types=1);

namespace App\Application\Vehicles\Power;

use App\Application\Events\OutboxWriter;
use App\Application\Rules\RuleEngine;
use App\Models\InsuranceProduct;
use App\Models\User;
use App\Models\Vehicles\VehicleVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cameroon automobile stamp duty (droit de timbre automobile) — Vehicle Power master cameroon_automobile_stamp_duty.
 *
 * Not a second tax engine: this resolves the INPUTS (verified fiscal_power_cv → band → effective-dated schedule rate,
 * transport-licence eligibility, rule-engine exemption) and hands rating v2 one FIXED charge line (charge code
 * AUTOMOBILE_STAMP_DUTY) through chargeTable(); DeterministicRatingEngine prices it with every other tax.
 *
 *  - VPWR-008: the band comes from the verified fiscal_power_cv only (never power_hp / kW / PS / the declared fact).
 *  - VPWR-009: PUBLIC_PASSENGER_AND_GOODS_TRANSPORT needs a verified VALID transport licence; otherwise OTHER_VEHICLES.
 *  - Unknown fiscal power while an approved schedule is in force → REVIEW_REQUIRED (never guessed).
 *  - Exemptions come only from approved TAX_EXEMPTION rule sets (RuleEngine::taxExemptions), never from make/model.
 *  - Rate changes are new schedule versions (approved rates are immutable, DB trigger); policies keep their snapshot.
 */
final class VehicleStampDutyService
{
    public const CHARGE_CODE = 'AUTOMOBILE_STAMP_DUTY';

    public const TRANSPORT = 'PUBLIC_PASSENGER_AND_GOODS_TRANSPORT';

    public const OTHER = 'OTHER_VEHICLES';

    public const SCHEDULES = [self::TRANSPORT, self::OTHER];

    /** Lines whose issuance collects the automobile stamp duty (motor third-party liability). */
    public const LINES = ['MOTOR'];

    public function __construct(
        private readonly FiscalPowerService $fiscal,
        private readonly FiscalPowerBands $bands,
        private readonly VehiclePowerAudit $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    // ------------------------------------------------------------------ rate schedules (effective-dated versions)

    /** @return list<array<string, mixed>> */
    public function schedules(?string $at = null, bool $all = false): array
    {
        $q = DB::table('vehicle_stamp_duty_rate_schedules')->orderBy('schedule_code')->orderByDesc('version');
        if (! $all) {
            $day = $at ?? now()->toDateString();
            $q->where('status', 'APPROVED')->whereDate('effective_from', '<=', $day)->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day));
        }

        return $q->get()->map(fn ($s) => $this->present($s))->all();
    }

    /** POST master-data/fiscal-power/rate-schedules/version — maker step: a new DRAFT version (never an edit). */
    public function createVersion(array $d, User $actor): array
    {
        $code = strtoupper((string) ($d['schedule_code'] ?? ''));
        if (! in_array($code, self::SCHEDULES, true)) {
            throw ValidationException::withMessages(['schedule_code' => 'Unknown stamp duty schedule.']);
        }
        $rates = (array) ($d['rates_xaf'] ?? []);
        $bandCodes = array_map(fn ($b) => $b->code, $this->bands->all());
        if (array_diff($bandCodes, array_keys($rates)) !== [] || array_diff(array_keys($rates), $bandCodes) !== []) {
            throw ValidationException::withMessages(['rates_xaf' => 'A rate is required for every fiscal power band ('.implode(', ', $bandCodes).').']);
        }
        foreach ($rates as $band => $xaf) {
            if (! is_int($xaf) || $xaf < 0) {
                throw ValidationException::withMessages(["rates_xaf.{$band}" => 'Rates are whole XAF amounts.']);
            }
        }
        if (blank($d['effective_from'] ?? null) || blank($d['legal_reference'] ?? null)) {
            throw ValidationException::withMessages(['legal_reference' => 'effective_from and legal_reference are required.']);
        }

        return DB::transaction(function () use ($code, $rates, $d, $actor) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['stamp_duty_schedule:'.$code]);
            $prev = DB::table('vehicle_stamp_duty_rate_schedules')->where('schedule_code', $code)->orderByDesc('version')->first();
            $id = (string) Str::uuid();
            DB::table('vehicle_stamp_duty_rate_schedules')->insert(['id' => $id, 'schedule_code' => $code, 'version' => ((int) ($prev->version ?? 0)) + 1, 'status' => 'DRAFT',
                'label_fr' => $d['label_fr'] ?? $prev->label_fr ?? $code, 'jurisdiction' => 'CM', 'currency' => 'XAF',
                'transport_license_required' => $code === self::TRANSPORT, 'license_verification_status_required' => $code === self::TRANSPORT ? 'VALID' : null,
                'effective_from' => $d['effective_from'], 'effective_until' => $d['effective_until'] ?? null, 'data_status' => 'OWNER_PROVIDED',
                'source_status' => $d['source_status'] ?? null, 'source_ids' => json_encode(array_values((array) ($d['source_ids'] ?? []))),
                'legal_reference' => $d['legal_reference'], 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($rates as $band => $xaf) {
                DB::table('vehicle_stamp_duty_rates')->insert(['id' => (string) Str::uuid(), 'schedule_id' => $id, 'band_code' => $band, 'rate_xaf' => $xaf, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('vehicle_stamp_duty_rate_schedule', $id, 'stamp_duty_schedule.drafted', null, 'DRAFT', $actor->id, ['schedule_code' => $code, 'rates_xaf' => $rates]);

            return $this->present(DB::table('vehicle_stamp_duty_rate_schedules')->where('id', $id)->first());
        });
    }

    /** Checker step (≠ maker): DRAFT → APPROVED; the previous open-ended version is closed the day before (history kept). */
    public function approve(string $id, User $actor): array
    {
        return DB::transaction(function () use ($id, $actor) {
            $s = DB::table('vehicle_stamp_duty_rate_schedules')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            if ($s->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'Only a DRAFT schedule version can be approved.']);
            }
            if ($s->created_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => 'Maker-checker: the approver must differ from the maker.']);
            }
            $overlaps = DB::table('vehicle_stamp_duty_rate_schedules')->where('schedule_code', $s->schedule_code)->where('status', 'APPROVED')
                ->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>=', $s->effective_from))
                ->whereDate('effective_from', '<=', $s->effective_until ?? '9999-12-31')->lockForUpdate()->get();
            foreach ($overlaps as $o) {
                if ($o->effective_from >= $s->effective_from) {
                    throw ValidationException::withMessages(['effective_from' => 'An approved version already starts on or after this date; choose a later effective date.']);
                }
                DB::table('vehicle_stamp_duty_rate_schedules')->where('id', $o->id)
                    ->update(['effective_until' => now()->parse($s->effective_from)->subDay()->toDateString(), 'updated_at' => now()]);
            }
            DB::table('vehicle_stamp_duty_rate_schedules')->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->record('vehicle_stamp_duty_rate_schedule', $id, 'stamp_duty_schedule.approved', 'DRAFT', 'APPROVED', $actor->id,
                ['schedule_code' => $s->schedule_code, 'version' => (int) $s->version, 'closed_versions' => $overlaps->pluck('id')->all()]);
            $this->outbox->record('vehicle.stamp_duty_schedule.approved', 'vehicle_stamp_duty_rate_schedule', $id, ['schedule_code' => $s->schedule_code,
                'version' => (int) $s->version, 'effective_from' => $s->effective_from, 'closed_versions' => $overlaps->pluck('id')->all()]);

            return $this->present(DB::table('vehicle_stamp_duty_rate_schedules')->where('id', $id)->first());
        });
    }

    // ------------------------------------------------------------------ transport licences (VPWR-009)

    public function recordLicence(string $tenantId, array $d, User $actor): object
    {
        $reg = FiscalPowerService::normalizeRegistration($d['registration_number'] ?? null)
            ?? throw ValidationException::withMessages(['registration_number' => 'A registration number is required.']);
        $id = (string) Str::uuid();
        DB::table('vehicle_transport_licences')->insert(['id' => $id, 'tenant_id' => $tenantId, 'registration_number' => $reg, 'licence_number' => $d['licence_number'],
            'licence_type' => $d['licence_type'] ?? null, 'issuing_authority' => $d['issuing_authority'] ?? null, 'valid_from' => $d['valid_from'],
            'valid_until' => $d['valid_until'] ?? null, 'status' => 'PENDING', 'source_document_id' => $d['source_document_id'] ?? null,
            'created_by' => $actor->id, 'notes' => $d['notes'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('vehicle_transport_licence', $id, 'transport_licence.recorded', null, 'PENDING', $actor->id, ['registration_number' => $reg]);

        return DB::table('vehicle_transport_licences')->where('id', $id)->first();
    }

    /** Checker: PENDING → VALID | REJECTED; VALID → EXPIRED | SUSPENDED | REVOKED. */
    public function decideLicence(string $tenantId, string $id, string $status, User $actor, ?string $notes = null): object
    {
        return DB::transaction(function () use ($tenantId, $id, $status, $actor, $notes) {
            $l = DB::table('vehicle_transport_licences')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $allowed = ['PENDING' => ['VALID', 'REJECTED'], 'VALID' => ['EXPIRED', 'SUSPENDED', 'REVOKED'], 'SUSPENDED' => ['VALID', 'REVOKED']][$l->status] ?? [];
            if (! in_array($status, $allowed, true)) {
                throw ValidationException::withMessages(['status' => "A {$l->status} licence cannot become {$status}."]);
            }
            if ($status === 'VALID' && $l->created_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => 'Maker-checker: the verifier must differ from the person who recorded the licence.']);
            }
            DB::table('vehicle_transport_licences')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]
                + ($status === 'VALID' ? ['verified_by' => $actor->id, 'verified_at' => now()] : []));
            $this->audit->record('vehicle_transport_licence', $id, 'transport_licence.'.strtolower($status), $l->status, $status, $actor->id, [], $notes);
            if ($status === 'VALID') {
                $this->outbox->record('vehicle.transport_licence.verified', 'vehicle_transport_licence', $id, ['registration_number' => $l->registration_number, 'valid_until' => $l->valid_until]);
            }

            return DB::table('vehicle_transport_licences')->where('id', $id)->first();
        });
    }

    public function validLicence(?string $tenantId, ?string $registration, string $at): ?object
    {
        $reg = FiscalPowerService::normalizeRegistration($registration);
        if ($tenantId === null || $reg === null) {
            return null;
        }

        return DB::table('vehicle_transport_licences')->where('tenant_id', $tenantId)->where('registration_number', $reg)->where('status', 'VALID')
            ->whereNotNull('verified_by')->whereDate('valid_from', '<=', $at)->where(fn ($w) => $w->whereNull('valid_until')->orWhereDate('valid_until', '>=', $at))
            ->orderByDesc('valid_from')->first();
    }

    // ------------------------------------------------------------------ resolution for rating / issuance

    /**
     * The stamp duty inputs for a motor risk on $at. status:
     *   NOT_APPLICABLE  line does not collect the duty
     *   NOT_CONFIGURED  no approved schedule in force (nothing charged; rating unchanged)
     *   REVIEW_REQUIRED fiscal power unknown / unverified / outside the bands → route to verification, never guess
     *   EXEMPT          an approved TAX_EXEMPTION rule exempts AUTOMOBILE_STAMP_DUTY
     *   APPLIED         band + schedule + rate resolved
     */
    public function resolve(string $lineCode, array $facts, ?string $tenantId, string $at, ?InsuranceProduct $product = null): array
    {
        $base = ['charge_code' => self::CHARGE_CODE, 'reference_date' => $at, 'line_code' => strtoupper($lineCode)];
        if (! in_array(strtoupper($lineCode), self::LINES, true)) {
            return ['status' => 'NOT_APPLICABLE'] + $base;
        }
        $schedules = collect($this->schedules($at))->keyBy('schedule_code');
        if (! $schedules->has(self::OTHER) && ! $schedules->has(self::TRANSPORT)) {
            return ['status' => 'NOT_CONFIGURED'] + $base;
        }
        $registration = is_scalar($facts['registration_number'] ?? null) ? (string) $facts['registration_number'] : null;
        $variantId = null;
        if (is_scalar($facts['vehicle_variant_code'] ?? null)) {
            $variantId = VehicleVariant::where('code', strtoupper((string) $facts['vehicle_variant_code']))->value('id');
        }
        $fp = $this->fiscal->currentFor($variantId, $registration, $at);
        $declared = is_numeric($facts['fiscal_power'] ?? null) ? (int) $facts['fiscal_power'] : null;
        $base += ['registration_number' => FiscalPowerService::normalizeRegistration($registration), 'variant_id' => $variantId, 'declared_fiscal_power' => $declared];
        if ($fp === null) {
            return ['status' => 'REVIEW_REQUIRED', 'reason' => 'FISCAL_POWER_UNVERIFIED', 'fiscal_power_verification_status' => 'PENDING_FISCAL_POWER_VERIFICATION'] + $base;
        }
        $band = $this->bands->codeFor($fp['fiscal_power_cv']);
        $base += ['fiscal_power_cv' => $fp['fiscal_power_cv'], 'fiscal_power_band_code' => $band, 'fiscal_power_record_id' => $fp['record_id'],
            'fiscal_power_source_type' => $fp['source_type'], 'fiscal_power_source_reference' => $fp['source_reference'],
            'fiscal_power_verification_status' => $fp['verification_status'], 'fiscal_power_verified_at' => $fp['verified_at'], 'fiscal_power_version' => $fp['version']];
        if ($band === null) {
            return ['status' => 'REVIEW_REQUIRED', 'reason' => 'FISCAL_POWER_OUTSIDE_BANDS'] + $base;
        }
        $licence = $this->validLicence($tenantId, $registration, $at);
        $code = $licence && $schedules->has(self::TRANSPORT) ? self::TRANSPORT : self::OTHER;
        if (! $schedules->has($code)) {
            return ['status' => 'REVIEW_REQUIRED', 'reason' => 'SCHEDULE_NOT_IN_FORCE:'.$code] + $base;
        }
        $schedule = $schedules[$code];
        $base += ['schedule_code' => $code, 'schedule_id' => $schedule['id'], 'schedule_version' => $schedule['version'],
            'transport_licence' => $licence ? ['id' => $licence->id, 'licence_number' => $licence->licence_number, 'status' => $licence->status, 'valid_until' => $licence->valid_until] : null,
            'transport_schedule_eligible' => $licence !== null];

        $exemptions = app(RuleEngine::class)->taxExemptions($lineCode, $product, $facts + [
            'fiscal_power.cv' => $fp['fiscal_power_cv'], 'fiscal_power.band_code' => $band, 'fiscal_power.schedule_code' => $code,
            'transport_licence.status' => $licence ? 'VALID' : null,
        ], new \DateTimeImmutable($at));
        if ($ex = $exemptions['exempt'][self::CHARGE_CODE] ?? null) {
            return ['status' => 'EXEMPT', 'rate_xaf' => 0, 'exemption' => $ex, 'rule_versions' => $exemptions['versions']] + $base;
        }

        return ['status' => 'APPLIED', 'rate_xaf' => (int) $schedule['rates_xaf'][$band], 'rule_versions' => $exemptions['versions']] + $base;
    }

    /** Rating v2 tax table (DeterministicRatingEngine charges contract) for an APPLIED snapshot; null otherwise. */
    public static function chargeTable(array $snapshot): ?array
    {
        if (($snapshot['status'] ?? null) !== 'APPLIED') {
            return null;
        }

        return ['key' => 'CM/'.self::CHARGE_CODE.'/'.$snapshot['schedule_code'], 'source_table' => 'vehicle_stamp_duty_rate_schedules', 'source_id' => $snapshot['schedule_id'],
            'version' => (int) $snapshot['schedule_version'], 'data_status' => 'OWNER_CONFIRMED',
            'rules' => ['charges' => [['code' => self::CHARGE_CODE, 'kind' => 'TAX', 'basis' => 'FIXED', 'fixed_minor' => (int) $snapshot['rate_xaf']]]]];
    }

    /**
     * Issuance gate (workflow_dependencies.motor_policy_issuance): a motor offer priced while the duty was due must
     * carry a resolved fiscal-power snapshot; an offer priced before any schedule was in force is re-checked now.
     */
    public function assertIssuable(?string $ratingRunId, string $lineCode, array $facts, ?string $tenantId): void
    {
        if (! in_array(strtoupper($lineCode), self::LINES, true)) {
            return;
        }
        $snap = $ratingRunId ? json_decode((string) DB::table('rating_runs')->where('id', $ratingRunId)->value('fiscal_power_snapshot'), true) : null;
        if (is_array($snap) && in_array($snap['status'] ?? null, ['APPLIED', 'EXEMPT'], true)) {
            return;
        }
        $now = $this->resolve($lineCode, $facts, $tenantId, now()->toDateString());
        if (in_array($now['status'], ['NOT_APPLICABLE', 'NOT_CONFIGURED'], true) && (! is_array($snap) || $snap['status'] !== 'REVIEW_REQUIRED')) {
            return;
        }
        throw ValidationException::withMessages(['fiscal_power' => 'REVIEW_REQUIRED: the automobile stamp duty needs a verified fiscal power (CV fiscal) for this vehicle; re-rate after verification.']);
    }

    private function present(object $s): array
    {
        return ['id' => $s->id, 'schedule_code' => $s->schedule_code, 'version' => (int) $s->version, 'status' => $s->status, 'label_fr' => $s->label_fr,
            'jurisdiction' => $s->jurisdiction, 'currency' => $s->currency, 'transport_license_required' => (bool) $s->transport_license_required,
            'license_verification_status_required' => $s->license_verification_status_required, 'effective_from' => $s->effective_from, 'effective_until' => $s->effective_until,
            'data_status' => $s->data_status, 'source_status' => $s->source_status, 'source_ids' => json_decode((string) $s->source_ids, true) ?: [], 'legal_reference' => $s->legal_reference,
            'approved_by' => $s->approved_by, 'approved_at' => $s->approved_at,
            'rates_xaf' => DB::table('vehicle_stamp_duty_rates')->where('schedule_id', $s->id)->pluck('rate_xaf', 'band_code')->map(fn ($v) => (int) $v)->all()];
    }
}
