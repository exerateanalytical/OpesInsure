<?php

declare(strict_types=1);

namespace App\Application\Rating;

use App\Application\Audit\AuditWriter;
use App\Application\Shared\CanonicalJson;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-RAT-003 — taxes, levies and fees as configurable versioned tables (tax_levy_versions,
 * fee_schedule_versions), never hard-coded. Every charge names a catalogued charge code; rates stay
 * DEMO_UNVERIFIED until the owner confirms them (OQ-9).
 *
 * Owner decision 10 (2026-09-25): demo / unverified rates never silently become production rates. A table is
 * created DEMO (or UNVERIFIED when a source is cited) and only becomes OWNER_CONFIRMED through the explicit,
 * audited, maker-checker verification step: requestVerification (legal basis + source) → confirmVerification by
 * another user. A database CHECK constraint enforces the same rule for any other writer.
 * Maker-checker approval and an overlap guard keep "exactly one version per key per date" for the
 * Temporal engine.
 */
final class ChargeTableService
{
    public const TABLES = ['tax' => 'tax_levy_versions', 'fee' => 'fee_schedule_versions'];

    public function __construct(private readonly CanonicalJson $json, private readonly AuditWriter $audit) {}

    public function create(string $kind, array $data, User $actor): object
    {
        $table = self::TABLES[$kind];
        $this->validateCharges($data['rules']['charges'] ?? [], $kind);
        $kinds = DB::table('rating_charge_codes')->pluck('kind', 'code');
        $data['rules']['charges'] = array_map(fn (array $c) => [...$c, 'kind' => $kinds[$c['code']]], $data['rules']['charges']);
        if (($data['data_status'] ?? 'DEMO_UNVERIFIED') === 'OWNER_CONFIRMED') {
            throw ValidationException::withMessages(['data_status' => empty($data['source_reference']) ? __('rating.owner_source_required')
                : 'A charge table cannot be created as OWNER_CONFIRMED: request verification (legal basis + source) and have a second user confirm it.']);
        }
        $confirmed = false;

        return DB::transaction(function () use ($table, $kind, $data, $actor, $confirmed) {
            $key = $kind === 'tax' ? ['jurisdiction' => $data['jurisdiction'] ?? 'CM', 'line_code' => $data['line_code']] : ['code' => $data['code'], 'tenant_id' => $data['tenant_id'] ?? null];
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$table.':'.json_encode($key)]);
            $q = DB::table($table);
            foreach ($key as $k => $v) {
                $q->where($k, $v);
            }
            $version = ((int) $q->max('version')) + 1;
            $id = (string) Str::uuid();
            DB::table($table)->insert([...$key, 'id' => $id, 'version' => $version, 'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null,
                'status' => 'DRAFT', 'rules' => $this->json->encode($data['rules']), 'rules_hash' => $this->json->hash($data['rules']),
                'data_status' => 'DEMO_UNVERIFIED', 'source_reference' => $data['source_reference'] ?? null, 'legal_basis' => $data['legal_basis'] ?? null,
                'verification_status' => empty($data['source_reference']) ? 'DEMO' : 'UNVERIFIED',
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('rating.charge_table.created', $table, $id, ['version' => $version, ...$key]);

            return DB::table($table)->where('id', $id)->first();
        });
    }

    public function approve(string $kind, string $id, User $actor): object
    {
        $table = self::TABLES[$kind];
        $row = DB::table($table)->where('id', $id)->first() ?? abort(404);
        if ($row->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => __('wave2.invalid_tariff_transition')]);
        }
        if ($row->created_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => __('wave2.maker_checker')]);
        }
        if ($this->json->hash(json_decode($row->rules, true)) !== $row->rules_hash) {
            throw ValidationException::withMessages(['rules' => __('wave2.tariff_hash_mismatch')]);
        }
        $q = DB::table($table)->where('status', 'APPROVED')->where('id', '!=', $id)
            ->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>=', $row->effective_from))
            ->whereDate('effective_from', '<=', $row->effective_until ?? '9999-12-31');
        foreach ($kind === 'tax' ? ['jurisdiction', 'line_code'] : ['code', 'tenant_id'] as $k) {
            $q->where($k, $row->{$k});
        }
        if ($q->exists()) {
            throw ValidationException::withMessages(['effective_from' => __('rating.charge_overlap')]);
        }
        DB::table($table)->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
        $this->audit->record('rating.charge_table.approved', $table, $id, ['rules_hash' => $row->rules_hash, 'data_status' => $row->data_status]);

        return DB::table($table)->where('id', $id)->first();
    }

    /**
     * Owner decision 10: an unverified (DEMO / UNVERIFIED / PENDING) rate is never used as a production value. On a real
     * production host (APP_ENV=production with demo mode off) rating refuses it; everywhere else it is priced with the
     * rating.charge_rates_unverified warning (DeterministicRatingEngine) so the result is visibly non-production.
     */
    public static function assertUsable(string $table, object $row): void
    {
        // Workflow Data Master: one production-use guard (OWNER_CONFIRMED maps to VERIFIED; DEMO_UNVERIFIED to DEMO_ONLY).
        \App\Application\DataReadiness\ProductionUseGuard::assertUsable('taxes_levies_fees', $row->data_status ?? null, "charge table {$table} {$row->id}",
            'Unverified tax / levy / fee rates cannot be used in production. Verify it first (legal basis + source, maker-checker).');
    }

    /** Maker step: cites the legal basis and source of the rates and asks a second user to verify them. */
    public function requestVerification(string $kind, string $id, array $data, User $actor): object
    {
        $table = self::TABLES[$kind];

        return DB::transaction(function () use ($table, $id, $data, $actor) {
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            if (! in_array($row->verification_status, ['DEMO', 'UNVERIFIED'], true) || $row->status === 'REJECTED') {
                throw ValidationException::withMessages(['verification_status' => "Verification cannot be requested from {$row->verification_status}."]);
            }
            foreach (['legal_basis', 'source_reference'] as $f) {
                if (blank($data[$f] ?? null)) {
                    throw ValidationException::withMessages([$f => "{$f} is required to verify tax / levy / fee rates."]);
                }
            }
            $evidence = array_filter(['source_document' => $data['source_document'] ?? null, 'notes' => $data['notes'] ?? null, 'rules_hash' => $row->rules_hash]);
            DB::table($table)->where('id', $id)->update(['legal_basis' => $data['legal_basis'], 'source_reference' => $data['source_reference'],
                'verification_status' => 'PENDING_VERIFICATION', 'verification_requested_by' => $actor->id, 'verification_requested_at' => now(),
                'verification_evidence' => $this->json->encode($evidence), 'updated_at' => now()]);
            $this->audit->recordChange('rating.charge_table.verification_requested', $table, $id, ['verification_status' => $row->verification_status],
                ['verification_status' => 'PENDING_VERIFICATION', 'legal_basis' => $data['legal_basis'], 'source_reference' => $data['source_reference']], 'Rate verification requested');

            return DB::table($table)->where('id', $id)->first();
        });
    }

    /** Checker step (≠ requester): VERIFIED → the table becomes OWNER_CONFIRMED; or rejected back to UNVERIFIED. */
    public function decideVerification(string $kind, string $id, bool $confirm, string $notes, User $actor): object
    {
        $table = self::TABLES[$kind];

        return DB::transaction(function () use ($table, $id, $confirm, $notes, $actor) {
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            if ($row->verification_status !== 'PENDING_VERIFICATION') {
                throw ValidationException::withMessages(['verification_status' => 'No verification is pending on this charge table.']);
            }
            if ($row->verification_requested_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => __('wave2.maker_checker')]);
            }
            if ($confirm && $this->json->hash(json_decode($row->rules, true)) !== $row->rules_hash) {
                throw ValidationException::withMessages(['rules' => __('wave2.tariff_hash_mismatch')]);
            }
            $after = $confirm
                ? ['verification_status' => 'VERIFIED', 'data_status' => 'OWNER_CONFIRMED', 'verified_by' => $actor->id, 'verified_at' => now(), 'verification_notes' => $notes]
                : ['verification_status' => 'UNVERIFIED', 'verification_notes' => $notes];
            DB::table($table)->where('id', $id)->update($after + ['updated_at' => now()]);
            $this->audit->recordChange('rating.charge_table.verification_'.($confirm ? 'confirmed' : 'rejected'), $table, $id,
                ['verification_status' => 'PENDING_VERIFICATION', 'data_status' => $row->data_status],
                ['verification_status' => $after['verification_status'], 'data_status' => $after['data_status'] ?? $row->data_status, 'rules_hash' => $row->rules_hash], $notes);

            return DB::table($table)->where('id', $id)->first();
        });
    }

    private function validateCharges(array $charges, string $kind): void
    {
        if ($charges === []) {
            throw ValidationException::withMessages(['rules.charges' => __('rating.charges_required')]);
        }
        $codes = DB::table('rating_charge_codes')->pluck('kind', 'code');
        foreach ($charges as $i => $c) {
            $catalogued = $codes[$c['code'] ?? ''] ?? null;
            $basis = $c['basis'] ?? null;
            $ok = $catalogued !== null && ($kind === 'fee') === ($catalogued === 'FEE')
                && in_array($basis, ['PREMIUM', 'PREMIUM_AND_FEES', 'FIXED'], true)
                && ($basis === 'FIXED' ? is_int($c['fixed_minor'] ?? null) && $c['fixed_minor'] >= 0 : is_int($c['basis_points'] ?? null) && $c['basis_points'] >= 0 && $c['basis_points'] <= 10000)
                && ! ($kind === 'fee' && $basis === 'PREMIUM_AND_FEES');
            if (! $ok) {
                throw ValidationException::withMessages(["rules.charges.$i" => __('rating.charge_invalid')]);
            }
        }
    }
}
