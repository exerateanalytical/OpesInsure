<?php

declare(strict_types=1);

namespace App\Application\FinancialDistribution;

use App\Application\Identity\PartyResolver;
use App\Models\Bordereau;
use App\Models\CommissionAccrual;
use App\Models\Partner;
use App\Models\PartnerStatement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Broker/agent-side "my finances" read surface: the caller's own Partner
 * org's commission accruals and statements, plus a tenant-wide bordereau
 * status overview for context.
 *
 * Ownership here is the caller's own Partner (via
 * PartyResolver::partnerForUser), not Party like every customer-facing
 * mobile batch — an agent/broker/carrier login represents an organisation
 * relationship, not an individual policyholder. CommissionAccrual and
 * PartnerStatement both carry partner_id, so they are scoped to it;
 * Bordereau carries no partner_id at all (it is a tenant-wide financial
 * document, not a per-partner one — see the batch report), so its status
 * overview is scoped to the tenant only, the same boundary
 * BrokerOperationsController already uses for it.
 *
 * Reads exclusively through the Wave6 FinancialDistribution Eloquent
 * models (the same ones BordereauService/CommissionService/
 * PartnerStatementService persist to) — the governed, tested,
 * maker-checker-enforced source of truth for this data. It deliberately
 * does not read through BrokerOperationsController or the legacy
 * Commissions/Settlements controllers, which write to the same tables via
 * a second, ungoverned path; see the batch report for that conflict.
 */
final class MobilePartnerFinanceService
{
    public function __construct(private PartyResolver $parties)
    {
    }

    /** @return array<string, mixed> */
    public function dashboard(User $user, string $tenantId): array
    {
        $partner = $this->resolvePartner($user, $tenantId);

        $commission = $partner
            ? CommissionAccrual::where('tenant_id', $tenantId)->where('partner_id', $partner->id)
                ->selectRaw('currency, SUM(amount_minor) as accrued_minor, SUM(vested_minor) as vested_minor, SUM(paid_minor) as paid_minor, SUM(clawed_back_minor) as clawed_back_minor, SUM(vested_minor - paid_minor) as outstanding_minor')
                ->groupBy('currency')->get()
                ->map(fn ($row) => $this->castMinorUnits($row, ['accrued_minor', 'vested_minor', 'paid_minor', 'clawed_back_minor', 'outstanding_minor']))
            : new Collection;

        $latestStatement = $partner
            ? PartnerStatement::where('tenant_id', $tenantId)->where('partner_id', $partner->id)->orderByDesc('period_end')->first()
            : null;

        return [
            'partner' => $partner ? ['id' => $partner->id, 'type' => $partner->type, 'status' => $partner->status] : null,
            'commission' => $commission,
            'latest_statement' => $latestStatement ? $this->statementSummary($latestStatement) : null,
            'bordereaux_status_counts' => $this->bordereauStatusCounts($tenantId),
        ];
    }

    /**
     * Outstanding commission per currency, read straight off the ledger
     * (CommissionAccrual) rather than recomputed from premiums — per the
     * patch guide's "Receivables ... come from the financial ledger, not
     * app calculations".
     */
    public function receivables(User $user, string $tenantId): Collection
    {
        $partner = $this->resolvePartner($user, $tenantId);

        if (! $partner) {
            return new Collection;
        }

        return CommissionAccrual::where('tenant_id', $tenantId)->where('partner_id', $partner->id)
            ->selectRaw('currency, SUM(amount_minor - clawed_back_minor) as earned_minor, SUM(vested_minor - paid_minor) as available_minor, SUM(paid_minor) as paid_minor')
            ->groupBy('currency')->get()
            ->map(fn ($row) => $this->castMinorUnits($row, ['earned_minor', 'available_minor', 'paid_minor']));
    }

    /**
     * Postgres SUM() over a bigint returns numeric, which PDO hands back as
     * a string — while a plain bigint column comes back as an int. Without
     * this the mobile client would get "4000" from an aggregate and 4000
     * from a column for the same kind of money field.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function castMinorUnits(CommissionAccrual $row, array $keys): array
    {
        $out = ['currency' => $row->currency];

        foreach ($keys as $key) {
            $out[$key] = (int) $row->getAttribute($key);
        }

        return $out;
    }

    public function commissionAccruals(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        $partner = $this->resolvePartner($user, $tenantId);
        $query = CommissionAccrual::where('tenant_id', $tenantId);
        $query = $partner ? $query->where('partner_id', $partner->id) : $query->whereRaw('1 = 0');

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function statements(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        $partner = $this->resolvePartner($user, $tenantId);
        $query = PartnerStatement::where('tenant_id', $tenantId);
        $query = $partner ? $query->where('partner_id', $partner->id) : $query->whereRaw('1 = 0');

        return $query->orderByDesc('period_end')->paginate($perPage);
    }

    public function statement(string $statementId, User $user, string $tenantId): PartnerStatement
    {
        $partner = $this->resolvePartner($user, $tenantId);
        $statement = $partner
            ? PartnerStatement::where('tenant_id', $tenantId)->where('partner_id', $partner->id)->find($statementId)
            : null;

        if (! $statement) {
            $exists = PartnerStatement::where('tenant_id', $tenantId)->where('id', $statementId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $statement->load('items');
    }

    private function resolvePartner(User $user, string $tenantId): ?Partner
    {
        $partner = $this->parties->partnerForUser($user);

        // Defense in depth: PartyResolver::partnerForUser resolves by
        // party_id alone, with no tenant filter of its own (a party can in
        // principle carry Partner rows in more than one tenant). Never
        // trust a Partner row from a different tenant than the one the
        // caller is currently scoped to via X-Tenant-Id.
        return $partner && $partner->tenant_id === $tenantId ? $partner : null;
    }

    /** @return array<string, int> */
    private function bordereauStatusCounts(string $tenantId): array
    {
        return Bordereau::where('tenant_id', $tenantId)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();
    }

    /** @return array<string, mixed> */
    private function statementSummary(PartnerStatement $statement): array
    {
        return [
            'id' => $statement->id,
            'statement_number' => $statement->statement_number,
            'status' => $statement->status,
            'period_start' => $statement->period_start?->toDateString(),
            'period_end' => $statement->period_end?->toDateString(),
            'currency' => $statement->currency,
            'closing_balance_minor' => $statement->closing_balance_minor,
            'published_at' => $statement->published_at?->toIso8601String(),
        ];
    }
}
