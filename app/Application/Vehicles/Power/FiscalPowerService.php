<?php

declare(strict_types=1);

namespace App\Application\Vehicles\Power;

use App\Application\Cases\CaseService;
use App\Application\Events\OutboxWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cameroon fiscal power (puissance administrative / CV fiscal) — vehicle_fiscal_power_records.
 *
 * Sources: CIVIC > Cameroon registration document > Cameroon authority data > manual entry backed by an uploaded
 * authoritative document under maker-checker. There is NO calculation path (calculation_policy
 * NO_AUTOMATIC_CALCULATION_UNTIL_OFFICIAL_FORMULA_VERIFIED): the value is never derived from hp, kW, PS, displacement,
 * cylinders or model name (VPWR-003/006), and a technical unit is refused on input.
 *
 * Review workflow: DRAFT → SOURCE_ATTACHED (provenance) → PENDING_REVIEW (required fields) → VERIFIED (authorized
 * reviewer ≠ maker) | REJECTED; a VERIFIED value is never overwritten (DB trigger, VPWR-007) — a new version
 * supersedes it; conflicting authoritative values → CONFLICT_REVIEW_REQUIRED + a DATA_STEWARD case.
 */
final class FiscalPowerService
{
    public const SOURCE_PRIORITY = ['CIVIC' => 1, 'CAMEROON_REGISTRATION_DOCUMENT' => 2, 'CAMEROON_AUTHORITY_DATA' => 3, 'MANUAL_VERIFIED' => 4];

    public const VERIFIED_STATUS = ['CIVIC' => 'VERIFIED_CIVIC', 'CAMEROON_REGISTRATION_DOCUMENT' => 'VERIFIED_REGISTRATION',
        'CAMEROON_AUTHORITY_DATA' => 'VERIFIED_AUTHORITY_DATA', 'MANUAL_VERIFIED' => 'VERIFIED_MANUAL_DOCUMENT'];

    /** Units / keys that are technical power and must never land in fiscal_power_cv (VPWR-003). */
    private const TECHNICAL_UNITS = ['PS', 'CV_DIN', 'CV DIN', 'METRIC_PS', 'METRIC_HP', 'HP', 'BHP', 'MECHANICAL_HP', 'KW', 'KILOWATT'];

    private const DERIVATION_KEYS = ['power_ps', 'power_hp', 'power_kw', 'displacement_cc', 'engine_capacity_cc', 'cylinder_count', 'derived_from', 'formula'];

    public function __construct(
        private readonly FiscalPowerBands $bands,
        private readonly VehiclePowerAudit $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public static function normalizeRegistration(?string $reg): ?string
    {
        $r = $reg === null ? '' : strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $reg));

        return $r === '' ? null : $r;
    }

    /**
     * Maker step: capture a fiscal power value. The record advances as far as its content allows
     * (DRAFT → SOURCE_ATTACHED → PENDING_REVIEW); it never becomes VERIFIED here.
     *
     * @param  array{variant_id?: ?string, registration_number?: ?string, vin?: ?string, fiscal_power_cv: mixed, fiscal_power_unit?: string,
     *   source_type?: string, source_reference?: string, source_document_id?: string, source_url?: string, effective_from?: string, effective_until?: string, notes?: string}  $d
     */
    public function submit(array $d, User $actor, ?string $tenantId = null): object
    {
        $this->guardNotTechnical($d);
        $cv = $this->cv($d['fiscal_power_cv'] ?? null);
        $source = $this->sourceType($d['source_type'] ?? null);
        $reg = self::normalizeRegistration($d['registration_number'] ?? null);
        if (empty($d['variant_id']) && $reg === null && empty($d['vin'])) {
            throw ValidationException::withMessages(['variant_id' => 'A variant, registration number or VIN is required.']);
        }

        return DB::transaction(function () use ($d, $actor, $tenantId, $cv, $source, $reg) {
            $key = $this->subjectKey($d['variant_id'] ?? null, $reg);
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['fiscal_power:'.$key]);
            $version = (int) $this->subjectQuery($d['variant_id'] ?? null, $reg)->max('version') + 1;
            $id = (string) Str::uuid();
            DB::table('vehicle_fiscal_power_records')->insert([
                'id' => $id, 'tenant_id' => $reg !== null ? $tenantId : null, 'variant_id' => $d['variant_id'] ?? null, 'registration_number' => $reg, 'vin' => $d['vin'] ?? null,
                'version' => $version, 'review_state' => 'DRAFT', 'fiscal_power_cv' => $cv, 'fiscal_power_band_code' => null,
                'source_type' => $source, 'source_reference' => $d['source_reference'] ?? null, 'source_document_id' => $d['source_document_id'] ?? null,
                'source_url' => $d['source_url'] ?? null, 'jurisdiction' => 'CM', 'effective_from' => $d['effective_from'] ?? now()->toDateString(),
                'effective_until' => $d['effective_until'] ?? null, 'verification_status' => 'PENDING_FISCAL_POWER_VERIFICATION',
                'created_by' => $actor->id, 'notes' => $d['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('vehicle_fiscal_power_record', $id, 'fiscal_power.drafted', null, 'DRAFT', $actor->id, ['fiscal_power_cv' => $cv, 'source_type' => $source, 'version' => $version]);
            $this->advance($id, $actor);

            return $this->find($id);
        });
    }

    /** DRAFT → SOURCE_ATTACHED: attach provenance to a draft (then auto-advances to PENDING_REVIEW when complete). */
    public function attachSource(string $id, array $d, User $actor): object
    {
        $this->guardNotTechnical($d);

        return DB::transaction(function () use ($id, $d, $actor) {
            $r = $this->lock($id);
            if ($r->review_state !== 'DRAFT') {
                throw ValidationException::withMessages(['review_state' => "Provenance can only be attached to a DRAFT record (is {$r->review_state})."]);
            }
            $upd = array_filter(['source_type' => isset($d['source_type']) ? $this->sourceType($d['source_type']) : null, 'source_reference' => $d['source_reference'] ?? null,
                'source_document_id' => $d['source_document_id'] ?? null, 'source_url' => $d['source_url'] ?? null,
                'fiscal_power_cv' => array_key_exists('fiscal_power_cv', $d) ? $this->cv($d['fiscal_power_cv']) : null], fn ($v) => $v !== null);
            DB::table('vehicle_fiscal_power_records')->where('id', $id)->update($upd + ['updated_at' => now()]);
            $this->advance($id, $actor);

            return $this->find($id);
        });
    }

    /**
     * Checker step (POST .../fiscal-power/verify): PENDING_REVIEW → VERIFIED by an authorized reviewer who is not the
     * maker; the previous VERIFIED version is SUPERSEDED (history kept). A different verified value for the same subject
     * is not overwritten: the record goes to CONFLICT_REVIEW_REQUIRED and a conflict case is opened.
     */
    public function verify(string $id, User $actor, ?string $notes = null, ?string $tenantId = null): object
    {
        return DB::transaction(function () use ($id, $actor, $notes, $tenantId) {
            $r = $this->lock($id);
            if ($r->review_state !== 'PENDING_REVIEW') {
                throw ValidationException::withMessages(['review_state' => "Only a PENDING_REVIEW record can be verified (is {$r->review_state})."]);
            }
            if (in_array($actor->id, array_filter([$r->created_by, $r->submitted_by]), true)) {
                throw ValidationException::withMessages(['actor' => 'Maker-checker: the reviewer must differ from the person who captured the fiscal power.']);
            }
            $this->assertProvenance($r);
            $current = $this->currentRow($r->variant_id, $r->registration_number, null, $r->id);
            if ($current && (int) $current->fiscal_power_cv !== (int) $r->fiscal_power_cv) {
                $this->conflict($r, $current, $actor, $tenantId, 'Two authoritative fiscal power values disagree.');

                return $this->find($id);
            }

            return $this->markVerified($r, $current, $actor, $notes);
        });
    }

    public function reject(string $id, User $actor, string $reason): object
    {
        return DB::transaction(function () use ($id, $actor, $reason) {
            $r = $this->lock($id);
            if (! in_array($r->review_state, ['DRAFT', 'SOURCE_ATTACHED', 'PENDING_REVIEW', 'CONFLICT_REVIEW_REQUIRED'], true)) {
                throw ValidationException::withMessages(['review_state' => "A {$r->review_state} record cannot be rejected."]);
            }
            DB::table('vehicle_fiscal_power_records')->where('id', $id)->update(['review_state' => 'REJECTED', 'verification_status' => 'RETIRED', 'updated_at' => now()]);
            DB::table('vehicle_fiscal_power_conflicts')->where('status', 'OPEN')->where('record_id', $id)
                ->update(['status' => 'RESOLVED', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution' => 'Challenger rejected: '.$reason, 'updated_at' => now()]);
            $this->audit->record('vehicle_fiscal_power_record', $id, 'fiscal_power.rejected', $r->review_state, 'REJECTED', $actor->id, [], $reason);

            return $this->find($id);
        });
    }

    /**
     * POST .../fiscal-power/conflict: an authoritative source reports a value different from the verified one. The
     * challenger is stored as a new version in CONFLICT_REVIEW_REQUIRED (never overwriting) and a case is opened.
     */
    public function reportConflict(array $d, User $actor, ?string $tenantId = null): object
    {
        $this->guardNotTechnical($d);
        $cv = $this->cv($d['fiscal_power_cv'] ?? null);
        $source = $this->sourceType($d['source_type'] ?? null);
        if ($cv === null || $source === null || blank($d['source_reference'] ?? null)) {
            throw ValidationException::withMessages(['source_reference' => 'A conflicting value needs fiscal_power_cv, source_type and source_reference.']);
        }
        $reg = self::normalizeRegistration($d['registration_number'] ?? null);

        return DB::transaction(function () use ($d, $actor, $tenantId, $cv, $source, $reg) {
            $current = $this->currentRow($d['variant_id'] ?? null, $reg) ?? throw ValidationException::withMessages(['variant_id' => 'No verified fiscal power to conflict with.']);
            $version = (int) $this->subjectQuery($d['variant_id'] ?? null, $reg)->max('version') + 1;
            $id = (string) Str::uuid();
            DB::table('vehicle_fiscal_power_records')->insert([
                'id' => $id, 'tenant_id' => $reg !== null ? $tenantId : null, 'variant_id' => $d['variant_id'] ?? null, 'registration_number' => $reg, 'vin' => $d['vin'] ?? null,
                'version' => $version, 'review_state' => 'PENDING_REVIEW', 'fiscal_power_cv' => $cv, 'source_type' => $source, 'source_reference' => $d['source_reference'],
                'source_document_id' => $d['source_document_id'] ?? null, 'source_url' => $d['source_url'] ?? null, 'jurisdiction' => 'CM',
                'effective_from' => $d['effective_from'] ?? now()->toDateString(), 'verification_status' => 'PENDING_FISCAL_POWER_VERIFICATION',
                'created_by' => $actor->id, 'submitted_by' => $actor->id, 'notes' => $d['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->conflict($this->find($id), $current, $actor, $tenantId, $d['notes'] ?? 'Conflicting authoritative fiscal power reported.');

            return $this->find($id);
        });
    }

    /** Checker resolves a conflict: keep the verified value (challenger rejected) or accept the challenger (new version supersedes). */
    public function resolveConflict(string $conflictId, bool $acceptChallenger, User $actor, string $reason): object
    {
        return DB::transaction(function () use ($conflictId, $acceptChallenger, $actor, $reason) {
            $c = DB::table('vehicle_fiscal_power_conflicts')->where('id', $conflictId)->lockForUpdate()->first() ?? abort(404);
            if ($c->status !== 'OPEN') {
                throw ValidationException::withMessages(['status' => 'The conflict is already resolved.']);
            }
            $challenger = $this->lock($c->record_id);
            if (in_array($actor->id, array_filter([$challenger->created_by, $challenger->submitted_by]), true)) {
                throw ValidationException::withMessages(['actor' => 'Maker-checker: the reviewer must differ from the person who captured the fiscal power.']);
            }
            if ($acceptChallenger) {
                $this->assertProvenance($challenger);
                $this->markVerified($challenger, $this->currentRow($challenger->variant_id, $challenger->registration_number, null, $challenger->id), $actor, $reason);
            } else {
                DB::table('vehicle_fiscal_power_records')->where('id', $challenger->id)->update(['review_state' => 'REJECTED', 'verification_status' => 'RETIRED', 'updated_at' => now()]);
            }
            DB::table('vehicle_fiscal_power_conflicts')->where('id', $conflictId)->update(['status' => 'RESOLVED', 'resolved_by' => $actor->id, 'resolved_at' => now(),
                'resolution' => ($acceptChallenger ? 'CHALLENGER_ACCEPTED: ' : 'VERIFIED_VALUE_KEPT: ').$reason, 'updated_at' => now()]);
            $this->audit->record('vehicle_fiscal_power_conflict', $conflictId, 'fiscal_power.conflict_resolved', 'OPEN', 'RESOLVED', $actor->id, ['accept_challenger' => $acceptChallenger], $reason);

            return DB::table('vehicle_fiscal_power_conflicts')->where('id', $conflictId)->first();
        });
    }

    /**
     * The VERIFIED fiscal power applicable on $at: a vehicle-specific record (registration) first, else the variant's.
     *
     * @return array{record_id: string, fiscal_power_cv: int, fiscal_power_band_code: ?string, source_type: string, source_reference: string, verification_status: string, verified_at: ?string, version: int}|null
     */
    public function currentFor(?string $variantId, ?string $registration, ?string $at = null): ?array
    {
        $reg = self::normalizeRegistration($registration);
        $row = ($reg !== null ? $this->currentRow(null, $reg, $at) : null) ?? ($variantId ? $this->currentRow($variantId, null, $at) : null);

        return $row ? ['record_id' => $row->id, 'fiscal_power_cv' => (int) $row->fiscal_power_cv, 'fiscal_power_band_code' => $row->fiscal_power_band_code,
            'source_type' => $row->source_type, 'source_reference' => $row->source_reference, 'source_document_id' => $row->source_document_id,
            'verification_status' => $row->verification_status, 'verified_at' => $row->verified_at, 'version' => (int) $row->version,
            'subject' => $row->registration_number !== null ? 'REGISTRATION' : 'VARIANT'] : null;
    }

    public function find(string $id): object
    {
        return DB::table('vehicle_fiscal_power_records')->where('id', $id)->first() ?? abort(404);
    }

    // ------------------------------------------------------------------ internals

    private function advance(string $id, User $actor): void
    {
        $r = $this->find($id);
        if ($r->review_state === 'DRAFT' && $this->hasProvenance($r)) {
            DB::table('vehicle_fiscal_power_records')->where('id', $id)->update(['review_state' => 'SOURCE_ATTACHED', 'updated_at' => now()]);
            $this->audit->record('vehicle_fiscal_power_record', $id, 'fiscal_power.source_attached', 'DRAFT', 'SOURCE_ATTACHED', $actor->id, ['source_type' => $r->source_type, 'source_reference' => $r->source_reference]);
            $r = $this->find($id);
        }
        if ($r->review_state === 'SOURCE_ATTACHED' && $r->fiscal_power_cv !== null && $r->effective_from !== null && $r->jurisdiction !== null) {
            DB::table('vehicle_fiscal_power_records')->where('id', $id)->update(['review_state' => 'PENDING_REVIEW', 'submitted_by' => $actor->id, 'updated_at' => now()]);
            $this->audit->record('vehicle_fiscal_power_record', $id, 'fiscal_power.submitted', 'SOURCE_ATTACHED', 'PENDING_REVIEW', $actor->id);
        }
    }

    private function markVerified(object $r, ?object $current, User $actor, ?string $notes): object
    {
        $band = $this->bands->codeFor((int) $r->fiscal_power_cv);
        if ($current) {
            $until = now()->parse($r->effective_from)->subDay()->toDateString();
            DB::table('vehicle_fiscal_power_records')->where('id', $current->id)->update(['review_state' => 'SUPERSEDED',
                'effective_until' => $current->effective_from <= $until ? $until : $current->effective_from, 'updated_at' => now()]);
            $this->audit->record('vehicle_fiscal_power_record', $current->id, 'fiscal_power.superseded', 'VERIFIED', 'SUPERSEDED', $actor->id, ['superseded_by' => $r->id]);
        }
        DB::table('vehicle_fiscal_power_records')->where('id', $r->id)->update(['review_state' => 'VERIFIED', 'verification_status' => self::VERIFIED_STATUS[$r->source_type],
            'fiscal_power_band_code' => $band, 'verified_by' => $actor->id, 'verified_at' => now(), 'supersedes_id' => $current?->id, 'updated_at' => now()]);
        $this->audit->record('vehicle_fiscal_power_record', $r->id, 'fiscal_power.verified', $r->review_state, 'VERIFIED', $actor->id,
            ['fiscal_power_cv' => (int) $r->fiscal_power_cv, 'band' => $band, 'source_type' => $r->source_type, 'source_reference' => $r->source_reference], $notes);
        $this->outbox->record('vehicle.fiscal_power.verified', 'vehicle_fiscal_power_record', $r->id, ['variant_id' => $r->variant_id, 'fiscal_power_cv' => (int) $r->fiscal_power_cv,
            'fiscal_power_band_code' => $band, 'verification_status' => self::VERIFIED_STATUS[$r->source_type], 'version' => (int) $r->version, 'supersedes_id' => $current?->id]);

        return $this->find($r->id);
    }

    private function conflict(object $challenger, object $current, User $actor, ?string $tenantId, string $note): void
    {
        DB::table('vehicle_fiscal_power_records')->where('id', $challenger->id)->update(['review_state' => 'CONFLICT_REVIEW_REQUIRED', 'verification_status' => 'CONFLICT_REVIEW_REQUIRED', 'updated_at' => now()]);
        $conflictId = (string) Str::uuid();
        $caseId = null;
        if ($tenantId !== null) {
            try {
                // Savepoint: a case-routing failure must never abort the conflict record.
                $caseId = DB::transaction(fn () => app(CaseService::class)->open($tenantId, 'DATA_STEWARD', [
                    'title' => 'Fiscal power conflict: '.($challenger->registration_number ?? $challenger->variant_id)." ({$current->fiscal_power_cv} CV vs {$challenger->fiscal_power_cv} CV)",
                    'subject_type' => 'vehicle_fiscal_power_record', 'subject_id' => $challenger->id, 'source_type' => 'vehicle_fiscal_power_conflict', 'source_id' => $conflictId,
                    'idempotency_key' => 'fiscal-power-conflict:'.$challenger->id,
                ], $actor)->id);
            } catch (Throwable $e) {
                report($e);
            }
        }
        DB::table('vehicle_fiscal_power_conflicts')->insert(['id' => $conflictId, 'record_id' => $challenger->id, 'conflicting_record_id' => $current->id,
            'values' => json_encode(['verified' => ['fiscal_power_cv' => (int) $current->fiscal_power_cv, 'source_type' => $current->source_type, 'source_reference' => $current->source_reference],
                'challenger' => ['fiscal_power_cv' => (int) $challenger->fiscal_power_cv, 'source_type' => $challenger->source_type, 'source_reference' => $challenger->source_reference]]),
            'case_id' => $caseId, 'status' => 'OPEN', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('vehicle_fiscal_power_record', $challenger->id, 'fiscal_power.conflict_detected', $challenger->review_state, 'CONFLICT_REVIEW_REQUIRED', $actor->id,
            ['conflict_id' => $conflictId, 'verified_record_id' => $current->id, 'case_id' => $caseId], $note);
        $this->outbox->record('vehicle.fiscal_power.conflict_detected', 'vehicle_fiscal_power_record', $challenger->id, ['conflict_id' => $conflictId,
            'verified_record_id' => $current->id, 'verified_cv' => (int) $current->fiscal_power_cv, 'challenger_cv' => (int) $challenger->fiscal_power_cv, 'case_id' => $caseId]);
    }

    private function currentRow(?string $variantId, ?string $reg, ?string $at = null, ?string $exceptId = null): ?object
    {
        $at ??= now()->toDateString();

        return $this->subjectQuery($variantId, $reg)->where('review_state', 'VERIFIED')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereDate('effective_from', '<=', $at)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $at))
            ->orderByDesc('version')->first()
            // A just-verified future-dated value still counts as "the verified value" for conflict detection.
            ?? ($exceptId ? $this->subjectQuery($variantId, $reg)->where('review_state', 'VERIFIED')->where('id', '!=', $exceptId)->orderByDesc('version')->first() : null);
    }

    private function subjectQuery(?string $variantId, ?string $reg)
    {
        $q = DB::table('vehicle_fiscal_power_records');

        return $reg !== null ? $q->where('registration_number', $reg) : $q->where('variant_id', $variantId)->whereNull('registration_number');
    }

    private function subjectKey(?string $variantId, ?string $reg): string
    {
        return $reg !== null ? 'REG:'.$reg : 'VAR:'.$variantId;
    }

    private function lock(string $id): object
    {
        return DB::table('vehicle_fiscal_power_records')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
    }

    private function hasProvenance(object $r): bool
    {
        return $r->source_type !== null && filled($r->source_reference) && ($r->source_type !== 'MANUAL_VERIFIED' || $r->source_document_id !== null);
    }

    /** VPWR-005: no VERIFIED status without approved provenance. */
    private function assertProvenance(object $r): void
    {
        if (! $this->hasProvenance($r) || ! isset(self::SOURCE_PRIORITY[$r->source_type])) {
            throw ValidationException::withMessages(['source_type' => 'VPWR-005: fiscal power without authoritative provenance cannot be VERIFIED.']);
        }
        if ($r->fiscal_power_cv === null || (int) $r->fiscal_power_cv <= 0) {
            throw ValidationException::withMessages(['fiscal_power_cv' => 'VPWR-004: fiscal_power_cv must be a positive integer when verified.']);
        }
    }

    /** VPWR-003 / VPWR-006: a technical unit or a derivation input is never accepted as fiscal power. */
    private function guardNotTechnical(array $d): void
    {
        $unit = strtoupper(trim((string) ($d['fiscal_power_unit'] ?? $d['unit'] ?? 'CV_FISCAL')));
        if ($unit !== 'CV_FISCAL') {
            throw ValidationException::withMessages(['fiscal_power_unit' => in_array($unit, self::TECHNICAL_UNITS, true)
                ? "VPWR-003: {$unit} is technical power and must never be stored in fiscal_power_cv." : 'fiscal_power_cv is expressed in CV_FISCAL only.']);
        }
        foreach (self::DERIVATION_KEYS as $k) {
            if (array_key_exists($k, $d)) {
                throw ValidationException::withMessages([$k => 'VPWR-006: fiscal power is never inferred from hp, kW, PS, displacement, cylinders or model; supply it from an authoritative source.']);
            }
        }
    }

    private function cv(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || (is_string($v) && ctype_digit($v))) {
            $n = (int) $v;
            if ($n > 0 && $n <= 999) {
                return $n;
            }
        }
        throw ValidationException::withMessages(['fiscal_power_cv' => 'VPWR-004: fiscal_power_cv must be a positive integer (CV fiscal).']);
    }

    private function sourceType(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = strtoupper((string) $v);

        return isset(self::SOURCE_PRIORITY[$s]) ? $s : throw ValidationException::withMessages(['source_type' => 'Fiscal power source must be CIVIC, CAMEROON_REGISTRATION_DOCUMENT, CAMEROON_AUTHORITY_DATA or MANUAL_VERIFIED.']);
    }
}
