<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Branch;

use App\Application\WebExperiences\Money;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Pages\BranchOverviewPage;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

/**
 * S2 branch scoping (2026-09-29) — base of the branch manager screens BRM-002/003/006..009/011/014..016 in /broker.
 * Opened by an ACTIVE BRANCH_MANAGER membership with a branch in the portal tenant (BranchOverviewPage::membership)
 * that also holds the screen's API permission (User::hasPermission, RBAC-exact). The branch is always the caller's
 * own, captured at mount and #[Locked] (never taken from the request). Rows are read through branch_id (migration
 * 2026_11_13_200001) — tenant AND branch filtered. Read-only; newest first, capped at LIMIT rows.
 */
abstract class BranchScreen extends Page implements HasTable
{
    use InteractsWithTable;

    public const LIMIT = 200;

    /** Screen id => page (BRM-001/004/005/010/012/013 are BranchOverviewPage). Mounted by BrokerPanelProvider. */
    public const SCREENS = [
        'BRM-002' => BranchProductionPage::class,
        'BRM-003' => BranchCustomersPage::class,
        'BRM-006' => BranchQuotesPage::class,
        'BRM-007' => BranchPoliciesPage::class,
        'BRM-008' => BranchRenewalsPage::class,
        'BRM-009' => BranchClaimsPage::class,
        'BRM-011' => BranchCommissionsPage::class,
        'BRM-014' => BranchApprovalsPage::class,
        'BRM-015' => BranchCompliancePage::class,
        'BRM-016' => BranchPerformancePage::class,
    ];

    /** The API permission the screen's data needs. */
    public const PERMISSION = '';

    /** lang key under branch_screens.nav / branch_screens.intro */
    public const KEY = '';

    protected string $view = 'filament.admin.pages.admin-screen';

    #[Locked]
    public ?string $tenantId = null;

    #[Locked]
    public ?string $branchId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return PortalScope::panel() === 'broker' && $user instanceof User && BranchOverviewPage::membership() !== null
            && $user->hasPermission(static::PERMISSION);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('branch_screens.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('branch_screens.nav.'.static::KEY);
    }

    public function getTitle(): string
    {
        return self::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        $name = $this->branchId ? DB::table('tenant_branches')->where('id', $this->branchId)->value('name') : null;

        return __('branch_screens.intro.'.static::KEY, ['branch' => $name ?? '—']);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $m = BranchOverviewPage::membership();
        $this->tenantId = $m->tenant_id;
        $this->branchId = $m->branch_id;
    }

    public function hydrate(): void
    {
        if ($this->tenantId !== null) {
            app(TenantContext::class)->set($this->tenantId);
        }
    }

    /** The caller's tenant AND branch rows of $table. */
    protected function scoped(string $table, ?string $alias = null): Builder
    {
        $a = $alias ?? $table;

        return DB::table($alias ? "{$table} as {$alias}" : $table)->where("{$a}.tenant_id", $this->tenantId)->where("{$a}.branch_id", $this->branchId);
    }

    /** User ids of the ACTIVE members of the caller's branch. */
    protected function branchUsers(): Builder
    {
        return DB::table('tenant_memberships')->where('tenant_id', $this->tenantId)->where('branch_id', $this->branchId)->where('status', 'ACTIVE')->select('user_id');
    }

    protected static function money(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)->label(__('branch_screens.columns.'.$label))->placeholder('—')
            ->formatStateUsing(fn ($state) => $state === null ? null : Money::display((int) $state, 'XAF'));
    }

    protected static function col(string $name, ?string $label = null): TextColumn
    {
        return TextColumn::make($name)->label(__('branch_screens.columns.'.($label ?? $name)))->placeholder('—');
    }

    protected static function kpi(string $key, mixed $value, ?string $tone = null, ?string $hint = null): array
    {
        return ['label' => __('branch_screens.kpis.'.$key), 'value' => $value, 'tone' => $tone, 'hint' => $hint];
    }

    public function kpis(): array
    {
        return [];
    }

    public function extraView(): ?array
    {
        return null;
    }

    public function showTable(): bool
    {
        return true;
    }

    /** @return array<string, array<string, mixed>> keyed rows (each with an 'id') */
    abstract protected function rows(): array;

    /** @return array<int, \Filament\Tables\Columns\Column> */
    abstract protected function columns(): array;

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if ($this->branchId === null) {
                    return [];
                }
                $rows = [];
                foreach ($this->rows() as $row) {
                    $row = (array) $row;
                    $rows[(string) $row['id']] = ['__key' => (string) $row['id']] + $row;
                }

                return $rows;
            })
            ->columns($this->columns())
            ->paginated(false)
            ->emptyStateHeading(__('branch_screens.empty'));
    }
}
