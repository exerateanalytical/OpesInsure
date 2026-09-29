<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Branch;

use App\Filament\Shared\Columns;
use App\Models\User;

/**
 * BRM-014 Branch Approvals — what waits on the branch manager (API permission cashier.sessions.approve): closed cashier
 * sessions awaiting decision and, when the caller also holds policies.read, undecided cancellation requests on the
 * branch's policies. Deciding stays on the existing cashier / cancellation screens (same services, maker-checker).
 */
final class BranchApprovalsPage extends BranchScreen
{
    public const PERMISSION = 'cashier.sessions.approve';

    public const KEY = 'approvals';

    protected static ?string $slug = 'branch/approvals';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-check-check';

    protected static ?int $navigationSort = 17;

    private function sessions(): \Illuminate\Database\Query\Builder
    {
        return $this->scoped('cashier_sessions', 's')->where('s.status', 'CLOSED')->whereNull('s.decided_at');
    }

    private function cancellations(): ?\Illuminate\Database\Query\Builder
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->hasPermission('policies.read')) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::table('policy_cancellations as c')->join('policies as x', 'x.id', '=', 'c.policy_id')
            ->where('c.tenant_id', $this->tenantId)->where('x.tenant_id', $this->tenantId)->where('x.branch_id', $this->branchId)
            ->whereNull('c.decided_at')->whereNotIn('c.status', ['APPROVED', 'REJECTED', 'CANCELLED', 'WITHDRAWN', 'EFFECTIVE', 'COMPLETED']);
    }

    public function kpis(): array
    {
        return [
            self::kpi('cash_to_review', (clone $this->sessions())->count(), (clone $this->sessions())->exists() ? 'warning' : 'success'),
            self::kpi('cancellations_pending', ($c = $this->cancellations()) ? $c->count() : '—'),
        ];
    }

    protected function rows(): array
    {
        $rows = [];
        foreach ($this->sessions()->leftJoin('users as u', 'u.id', '=', 's.cashier_user_id')->orderBy('s.closed_at')->limit(self::LIMIT)
            ->get(['s.id', 'u.full_name', 's.counted_cash_minor', 's.variance_minor', 's.closed_at']) as $s) {
            $rows[] = ['id' => 's:'.$s->id, 'kind' => __('branch_screens.kinds.cashier_session'), 'subject' => $s->full_name, 'amount_minor' => $s->counted_cash_minor,
                'detail' => __('branch_screens.variance', ['v' => \App\Application\WebExperiences\Money::display((int) $s->variance_minor, 'XAF')]), 'status' => 'CLOSED', 'since' => $s->closed_at];
        }
        if ($c = $this->cancellations()) {
            foreach ($c->orderBy('c.created_at')->limit(self::LIMIT)->get(['c.id', 'x.policy_number', 'c.reason_code', 'c.refund_minor', 'c.status', 'c.created_at']) as $r) {
                $rows[] = ['id' => 'c:'.$r->id, 'kind' => __('branch_screens.kinds.cancellation'), 'subject' => $r->policy_number, 'amount_minor' => $r->refund_minor,
                    'detail' => Columns::humanise($r->reason_code), 'status' => $r->status, 'since' => $r->created_at];
            }
        }

        return $rows;
    }

    protected function columns(): array
    {
        return [
            self::col('kind', 'type')->badge(), self::col('subject'), self::money('amount_minor', 'amount'), self::col('detail'),
            Columns::status('status', __('branch_screens.columns.status')), Columns::date('since', true, __('branch_screens.columns.waiting_since')),
        ];
    }
}
