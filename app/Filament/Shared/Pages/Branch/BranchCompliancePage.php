<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Branch;

use App\Filament\Shared\Columns;
use Illuminate\Support\Facades\DB;

/**
 * BRM-015 Branch Compliance — compliance items of the caller's branch (API permission customers.read): branch agents
 * whose licence is expired or expires within 60 days, and customers with an active branch policy whose latest KYC
 * submission is not APPROVED (or has expired / is missing).
 */
final class BranchCompliancePage extends BranchScreen
{
    public const PERMISSION = 'customers.read';

    public const KEY = 'compliance';

    protected static ?string $slug = 'branch/compliance';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-scale';

    protected static ?int $navigationSort = 18;

    private function licences(): \Illuminate\Database\Query\Builder
    {
        return $this->scoped('partners', 'pa')->leftJoin('parties as pp', 'pp.id', '=', 'pa.party_id')->where('pa.status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('pa.licence_expires_on')->orWhere('pa.licence_expires_on', '<=', now()->addDays(60)->toDateString()));
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function kyc(): \Illuminate\Support\Collection
    {
        $latest = DB::table('kyc_submissions')->where('tenant_id', $this->tenantId)->selectRaw('distinct on (party_id) party_id, status, expires_at')
            ->orderBy('party_id')->orderByDesc('created_at');

        return DB::table('parties as p')
            ->whereIn('p.id', $this->scoped('policies')->where('status', 'ACTIVE')->select('party_id'))
            ->leftJoinSub($latest, 'k', 'k.party_id', '=', 'p.id')
            ->where(fn ($q) => $q->whereNull('k.status')->orWhere('k.status', '!=', 'APPROVED')->orWhere('k.expires_at', '<', now()))
            ->limit(self::LIMIT)->get(['p.id', 'p.display_name', 'k.status', 'k.expires_at']);
    }

    public function kpis(): array
    {
        return [
            self::kpi('licences_attention', (clone $this->licences())->count(), 'warning'),
            self::kpi('kyc_attention', $this->kyc()->count(), 'warning'),
        ];
    }

    protected function rows(): array
    {
        $rows = [];
        foreach ((clone $this->licences())->limit(self::LIMIT)->get(['pa.id', 'pp.display_name', 'pa.legal_name', 'pa.licence_number', 'pa.licence_expires_on']) as $a) {
            $expired = $a->licence_expires_on === null || $a->licence_expires_on < now()->toDateString();
            $rows[] = ['id' => 'a:'.$a->id, 'kind' => __('branch_screens.kinds.agent_licence'), 'subject' => $a->display_name ?? $a->legal_name, 'reference' => $a->licence_number,
                'issue' => __('branch_screens.issues.'.($a->licence_expires_on === null ? 'licence_missing' : ($expired ? 'licence_expired' : 'licence_expiring'))), 'due' => $a->licence_expires_on];
        }
        foreach ($this->kyc() as $k) {
            $rows[] = ['id' => 'k:'.$k->id, 'kind' => __('branch_screens.kinds.customer_kyc'), 'subject' => $k->display_name, 'reference' => $k->status ? Columns::humanise($k->status) : null,
                'issue' => __('branch_screens.issues.'.($k->status === null ? 'kyc_missing' : ($k->status === 'APPROVED' ? 'kyc_expired' : 'kyc_incomplete'))), 'due' => $k->expires_at];
        }

        return $rows;
    }

    protected function columns(): array
    {
        return [
            self::col('kind', 'type')->badge(), self::col('subject'), self::col('reference'), self::col('issue'),
            Columns::date('due', false, __('branch_screens.columns.due')),
        ];
    }
}
