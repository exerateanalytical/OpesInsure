<?php

declare(strict_types=1);

namespace App\Application\Providers\Portal;

use App\Application\Providers\ProviderRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-PRV-003 — provider portal reads (FRP V, PRV-001…030). Every read is scoped to ONE provider resolved by
 * ProviderScope and, for insurer-owned data (networks, contracts, tariffs, claims, money), to the current tenant.
 * Health preauthorisations / provider claims are owned by other agents (Batch 14 E3/E4): they are read only when
 * their table exists and carries a provider_profile_id column, so this portal lights up without a code change.
 */
final class ProviderPortalService
{
    /** Candidate tables other batches create for provider-originated health work (read-only, guarded). */
    public const PREAUTH_TABLES = ['health_preauthorizations', 'preauthorizations', 'health_preauths'];

    public const PROVIDER_CLAIM_TABLES = ['health_provider_claims', 'provider_claims'];

    public function __construct(private readonly ProviderRegistry $providers) {}

    public function profile(ProviderScope $s): object
    {
        $p = $this->providers->find($s->providerId);
        $p->acts_for = $s->providerIds;

        return $p;
    }

    public function facilities(ProviderScope $s): array
    {
        return $this->providers->tree($s->providerId)->facilities;
    }

    public function services(ProviderScope $s): array
    {
        return DB::table('provider_facility_services as fs')->join('provider_facilities as f', 'f.id', '=', 'fs.provider_facility_id')
            ->join('medical_services as m', 'm.id', '=', 'fs.medical_service_id')->where('f.provider_profile_id', $s->providerId)
            ->orderBy('f.code')->orderBy('m.code')
            ->select('f.id as facility_id', 'f.code as facility_code', 'm.id as medical_service_id', 'm.code', 'm.name', 'm.category_code', 'fs.specialty_code')
            ->get()->all();
    }

    public function memberships(string $tenantId, ProviderScope $s): array
    {
        return DB::table('provider_network_memberships as m')->join('provider_networks as n', 'n.id', '=', 'm.provider_network_id')
            ->where('n.tenant_id', $tenantId)->where('m.provider_profile_id', $s->providerId)->orderByDesc('m.effective_from')
            ->select('m.id', 'm.provider_facility_id', 'm.effective_from', 'm.effective_to', 'm.status', 'n.id as network_id', 'n.code as network_code',
                'n.name as network_name', 'n.network_type_code', 'n.category')->get()->all();
    }

    public function contracts(string $tenantId, ProviderScope $s): array
    {
        return DB::table('provider_contracts as c')->join('provider_networks as n', 'n.id', '=', 'c.provider_network_id')
            ->where('c.tenant_id', $tenantId)->where('c.provider_profile_id', $s->providerId)->where('c.status', '<>', 'DRAFT')->orderByDesc('c.effective_from')
            ->select('c.id', 'c.contract_number', 'c.effective_from', 'c.effective_to', 'c.settlement_mode', 'c.status', 'n.id as network_id', 'n.name as network_name')
            ->get()->all();
    }

    /** Approved / superseded tariff versions of the provider's non-draft contracts, with their lines. */
    public function tariffs(string $tenantId, ProviderScope $s, ?string $contractId = null): array
    {
        $versions = DB::table('provider_tariff_versions as v')->join('provider_contracts as c', 'c.id', '=', 'v.provider_contract_id')
            ->where('c.tenant_id', $tenantId)->where('c.provider_profile_id', $s->providerId)->where('c.status', '<>', 'DRAFT')
            ->whereIn('v.status', ['APPROVED', 'SUPERSEDED'])->when($contractId, fn ($q, $id) => $q->where('c.id', $id))
            ->orderBy('c.contract_number')->orderByDesc('v.version')
            ->select('v.id', 'v.version', 'v.effective_from', 'v.effective_to', 'v.currency', 'v.status', 'v.approved_at', 'c.id as contract_id', 'c.contract_number')
            ->get();
        $lines = DB::table('provider_tariff_lines as l')->join('medical_services as m', 'm.id', '=', 'l.medical_service_id')
            ->whereIn('l.provider_tariff_version_id', $versions->pluck('id')->all())->orderBy('m.code')
            ->select('l.provider_tariff_version_id', 'm.id as medical_service_id', 'm.code', 'm.name', 'l.price_minor', 'l.contracted_price_minor', 'l.copay_minor', 'l.insurer_share_percent')
            ->get()->groupBy('provider_tariff_version_id');

        return $versions->map(function ($v) use ($lines) {
            $v->lines = ($lines[$v->id] ?? collect())->map(function ($l) {
                unset($l->provider_tariff_version_id);

                return $l;
            })->values()->all();

            return $v;
        })->all();
    }

    /** Claim expert / adjuster assignments given to this provider (claim_assignments EXPERT rows), minimal claim view. */
    public function assignments(string $tenantId, ProviderScope $s, ?string $status): array
    {
        if (! Schema::hasTable('claim_assignments') || ! Schema::hasColumn('claim_assignments', 'provider_profile_id')) {
            return [];
        }

        return DB::table('claim_assignments as a')->join('claims as c', 'c.id', '=', 'a.claim_id')
            ->where('a.tenant_id', $tenantId)->where('a.assignment_type', 'EXPERT')->where('a.provider_profile_id', $s->providerId)
            ->when($status, fn ($q, $v) => $q->where('a.status', $v))->orderByDesc('a.assigned_at')
            ->select('a.id', 'a.claim_id', 'a.status', 'a.assigned_at', 'a.accepted_at', 'a.inspection_scheduled_for', 'a.report_submitted_at',
                'a.fee_amount_minor', 'a.fee_currency', 'c.claim_number')->limit(500)->get()->all();
    }

    public function preauthorizations(string $tenantId, ProviderScope $s, ?string $status): array
    {
        return $this->guardedList(self::PREAUTH_TABLES, $tenantId, $s, $status);
    }

    public function providerClaims(string $tenantId, ProviderScope $s, ?string $status): array
    {
        return $this->guardedList(self::PROVIDER_CLAIM_TABLES, $tenantId, $s, $status);
    }

    /** PAYABLE obligations owed to the provider (creditor = its party, partner or profile) + per-currency totals. */
    public function statement(string $tenantId, ProviderScope $s, ?string $status): array
    {
        $rows = $this->payables($tenantId, $s)->when($status, fn ($q, $v) => $q->where('o.status', $v))->orderByDesc('o.due_at')
            ->select('o.id', 'o.type', 'o.source_type', 'o.source_id', 'o.source_reference', 'o.currency', 'o.amount_minor', 'o.outstanding_minor',
                'o.due_at', 'o.status', 'o.settled_at', 'o.description')->limit(1000)->get();
        $totals = $this->payables($tenantId, $s)->where('o.status', '<>', 'CANCELLED')->groupBy('o.currency')
            ->selectRaw('o.currency, SUM(o.amount_minor) as billed_minor, SUM(o.amount_minor - o.outstanding_minor) as paid_minor, SUM(o.outstanding_minor) as outstanding_minor')
            ->orderBy('o.currency')->get()->map(fn ($t) => ['currency' => $t->currency, 'billed_minor' => (int) $t->billed_minor,
                'paid_minor' => (int) $t->paid_minor, 'outstanding_minor' => (int) $t->outstanding_minor])->all();

        return ['obligations' => $rows->all(), 'totals' => $totals];
    }

    /** Settlements (payments) and reversals recorded against the provider's payables, newest first. */
    public function payments(string $tenantId, ProviderScope $s): array
    {
        return DB::table('financial_obligation_events as e')
            ->joinSub($this->payables($tenantId, $s)->select('o.id', 'o.currency', 'o.source_reference'), 'o', 'o.id', '=', 'e.financial_obligation_id')
            ->whereIn('e.event_type', ['SETTLEMENT', 'UNSETTLEMENT'])->orderByDesc('e.occurred_at')
            ->select('e.id', 'e.financial_obligation_id', 'e.event_type', 'e.amount_minor', 'o.currency', 'e.reference', 'e.status_after', 'e.occurred_at', 'o.source_reference')
            ->limit(1000)->get()->all();
    }

    // ----------------------------------------------------------------- internals

    private function payables(string $tenantId, ProviderScope $s): Builder
    {
        return DB::table('financial_obligations as o')->where('o.tenant_id', $tenantId)->where('o.kind', 'PAYABLE')
            ->where(function ($q) use ($s) {
                $q->where(fn ($w) => $w->whereRaw('LOWER(o.creditor_type) = ?', ['party'])->where('o.creditor_id', $s->partyId))
                    ->orWhere(fn ($w) => $w->whereRaw('LOWER(o.creditor_type) = ?', ['partner'])->where('o.creditor_id', $s->partnerId))
                    ->orWhere(fn ($w) => $w->whereRaw('LOWER(o.creditor_type) = ?', ['provider'])->where('o.creditor_id', $s->providerId));
            });
    }

    /** @param list<string> $tables */
    private function guardedList(array $tables, string $tenantId, ProviderScope $s, ?string $status): array
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'provider_profile_id')) {
                continue;
            }
            $cols = Schema::getColumnListing($table);
            // Internal medical / fraud notes never leave the insurer side (FRP V — EOB without internal notes).
            $hidden = array_filter($cols, fn ($c) => preg_match('/(internal|fraud|anomaly|reviewer_note|medical_note)/i', $c) === 1);

            return DB::table($table)->where('provider_profile_id', $s->providerId)
                ->when(in_array('tenant_id', $cols, true), fn ($q) => $q->where('tenant_id', $tenantId))
                ->when($status && in_array('status', $cols, true), fn ($q) => $q->where('status', $status))
                ->when(in_array('created_at', $cols, true), fn ($q) => $q->orderByDesc('created_at'))
                ->select(array_values(array_diff($cols, $hidden)))->limit(500)->get()->all();
        }

        return [];
    }
}
