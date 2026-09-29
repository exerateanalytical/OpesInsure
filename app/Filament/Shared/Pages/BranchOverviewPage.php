<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Application\WebExperiences\PortalScope;
use App\Application\WebExperiences\Money;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Columns;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

/**
 * BRM-001/004/005/010/012/013 (Q10 2026-09-29) — the branch manager's own branch in /broker. Only the data that is
 * branch-scoped in the platform is shown (branch_id on tenant_memberships, partners, cases, cashier_sessions,
 * sticker_reconciliations): staff and agents of the branch, open branch cases/tasks, cashier sessions awaiting review
 * and sticker counts. Policies, quotes, claims and commissions are tenant-scoped (no branch_id), so the remaining BRM
 * screens are not built. Opened by an ACTIVE BRANCH_MANAGER membership with a branch in the portal tenant; the branch
 * is always the caller's own (captured at mount, never taken from the request).
 */
final class BranchOverviewPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.admin-screen';

    protected static ?string $slug = 'branch';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-building-2';

    protected static ?int $navigationSort = 5;

    #[Locked]
    public ?string $tenantId = null;

    #[Locked]
    public ?string $branchId = null;

    public static function membership(?string $tenantId = null): ?object
    {
        $user = auth()->user();
        $tenantId ??= rescue(fn () => app(TenantContext::class)->id(), null, false);
        if (! $user instanceof User || $tenantId === null) {
            return null;
        }

        return DB::table('tenant_memberships')->where('user_id', $user->id)->where('tenant_id', $tenantId)->where('status', 'ACTIVE')
            ->where('role_code', 'BRANCH_MANAGER')->whereNotNull('branch_id')->first(['tenant_id', 'branch_id']);
    }

    public static function canAccess(): bool
    {
        return PortalScope::panel() === 'broker' && self::membership() !== null;
    }

    public static function getNavigationLabel(): string
    {
        return __('admin_screens.nav.branch_overview');
    }

    public function getTitle(): string
    {
        $name = $this->branchId ? DB::table('tenant_branches')->where('id', $this->branchId)->value('name') : null;

        return $name ? __('admin_screens.branch.title', ['branch' => $name]) : self::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return __('admin_screens.intro.branch_overview');
    }

    public function mount(): void
    {
        $m = self::membership();
        abort_if($m === null, 403);
        $this->tenantId = $m->tenant_id;
        $this->branchId = $m->branch_id;
    }

    public function hydrate(): void
    {
        if ($this->tenantId !== null) {
            app(TenantContext::class)->set($this->tenantId);
        }
    }

    private function scoped(string $table): \Illuminate\Database\Query\Builder
    {
        return DB::table($table)->where($table.'.tenant_id', $this->tenantId)->where($table.'.branch_id', $this->branchId);
    }

    public function kpis(): array
    {
        $k = fn (string $key, $value, ?string $tone = null, ?string $hint = null) => ['label' => __('admin_screens.kpis.'.$key), 'value' => $value, 'tone' => $tone, 'hint' => $hint];
        $cases = $this->scoped('cases')->whereNull('closed_at');
        $overdue = (clone $cases)->whereNotNull('due_at')->where('due_at', '<', now())->count();
        $cash = $this->scoped('cashier_sessions')->where('status', 'CLOSED')->whereNull('decided_at');

        return [
            $k('branch_staff', $this->scoped('tenant_memberships')->where('status', 'ACTIVE')->count(), null,
                __('admin_screens.kpis.agents_n', ['n' => $this->scoped('partners')->where('status', 'ACTIVE')->count()])),
            $k('open_cases', (clone $cases)->count(), $overdue > 0 ? 'warning' : null, __('admin_screens.kpis.overdue_n', ['n' => $overdue])),
            $k('cash_to_review', (clone $cash)->count(), (clone $cash)->exists() ? 'warning' : 'success',
                Money::display((int) (clone $cash)->sum('counted_cash_minor'), 'XAF')),
            $k('sticker_counts_open', $this->scoped('sticker_reconciliations')->whereNotIn('status', ['APPROVED', 'CLOSED', 'RECONCILED'])->count()),
        ];
    }

    public function extraView(): ?array
    {
        return null;
    }

    public function showTable(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if ($this->branchId === null) {
                    return [];
                }
                $rows = [];
                foreach ($this->scoped('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')
                    ->get(['tenant_memberships.id', 'tenant_memberships.user_id', 'users.full_name', 'tenant_memberships.role_code', 'tenant_memberships.status', 'tenant_memberships.created_at']) as $m) {
                    $open = DB::table('cases')->where('tenant_id', $this->tenantId)->where('branch_id', $this->branchId)->where('owner_user_id', $m->user_id)->whereNull('closed_at')->count();
                    $rows['m:'.$m->id] = ['__key' => 'm:'.$m->id, 'id' => 'm:'.$m->id, 'name' => $m->full_name, 'kind' => __('admin_screens.branch.staff'), 'role' => Columns::humanise($m->role_code),
                        'status' => $m->status, 'open_cases' => $open, 'since' => $m->created_at];
                }
                foreach ($this->scoped('partners')->leftJoin('parties', 'parties.id', '=', 'partners.party_id')
                    ->get(['partners.id', 'parties.display_name', 'partners.legal_name', 'partners.type', 'partners.agent_type', 'partners.status', 'partners.created_at']) as $p) {
                    $rows['p:'.$p->id] = ['__key' => 'p:'.$p->id, 'id' => 'p:'.$p->id, 'name' => $p->display_name ?? $p->legal_name ?? '—', 'kind' => __('admin_screens.branch.agent'),
                        'role' => Columns::humanise($p->agent_type ?? $p->type), 'status' => $p->status, 'open_cases' => null, 'since' => $p->created_at];
                }

                return $rows;
            })
            ->columns([
                TextColumn::make('name')->label(__('admin_screens.columns.name')),
                TextColumn::make('kind')->label(__('admin_screens.columns.type'))->badge(),
                TextColumn::make('role')->label(__('admin_screens.columns.role')),
                Columns::status('status', __('admin_screens.columns.status')),
                TextColumn::make('open_cases')->label(__('admin_screens.columns.open_cases'))->placeholder('—'),
                Columns::date('since', false, __('admin_screens.columns.since')),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
