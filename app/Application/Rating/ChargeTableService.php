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
 * DEMO_UNVERIFIED until the owner confirms them (OQ-9) — marking OWNER_CONFIRMED needs a source reference.
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
        $confirmed = ($data['data_status'] ?? 'DEMO_UNVERIFIED') === 'OWNER_CONFIRMED';
        if ($confirmed && empty($data['source_reference'])) {
            throw ValidationException::withMessages(['source_reference' => __('rating.owner_source_required')]);
        }

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
                'data_status' => $confirmed ? 'OWNER_CONFIRMED' : 'DEMO_UNVERIFIED', 'source_reference' => $data['source_reference'] ?? null,
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
