<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Tenancy\OrganizationStructureService;
use App\Filament\Shared\Columns;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** ADM-021 Organisational structure — GET organization/branches/tree (tenant.manage) via OrganizationStructureService::tree, shown as an indented tree. */
final class OrganisationTree extends AdminScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-network';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'organisation/structure';

    protected static array $permissions = ['tenant.manage'];

    protected static string $screen = 'organisation_tree';

    protected static string $group = 'Administration';

    /** @return array<string, array<string, mixed>> */
    public function rows(): array
    {
        if ($this->tenantId === null) {
            return [];
        }
        $managers = DB::table('users')->whereIn('id', DB::table('tenant_branches')->where('tenant_id', $this->tenantId)->whereNotNull('manager_user_id')->pluck('manager_user_id'))->pluck('full_name', 'id');
        $staff = DB::table('tenant_memberships')->where('tenant_id', $this->tenantId)->where('status', 'ACTIVE')->whereNotNull('branch_id')
            ->selectRaw('branch_id, count(*) as n')->groupBy('branch_id')->pluck('n', 'branch_id');
        $rows = [];
        $walk = function (array $nodes, int $depth) use (&$walk, &$rows, $managers, $staff): void {
            foreach ($nodes as $n) {
                $rows[$n['id']] = [
                    '__key' => $n['id'], 'id' => $n['id'], 'depth' => $depth,
                    'name' => str_repeat('— ', $depth).$n['name'], 'code' => $n['code'], 'status' => $n['status'],
                    'timezone' => $n['effective_timezone'] ?? $n['timezone'] ?? '—',
                    'manager' => $n['manager_user_id'] ? ($managers[$n['manager_user_id']] ?? '—') : '—',
                    'staff' => (int) ($staff[$n['id']] ?? 0),
                    'departments' => collect($n['departments'])->map(fn ($d) => $d->name.' ('.$d->code.')')->implode(', ') ?: '—',
                    'capabilities' => implode(', ', array_map('strval', array_keys(array_filter((array) $n['capabilities'])))) ?: '—',
                ];
                $walk($n['children'], $depth + 1);
            }
        };
        $walk(app(OrganizationStructureService::class)->tree($this->tenantId), 0);

        return $rows;
    }

    public function kpis(): array
    {
        $rows = $this->rows();

        return [
            self::kpi('branches', count($rows)),
            self::kpi('top_level', collect($rows)->where('depth', 0)->count()),
            self::kpi('levels', $rows === [] ? 0 : collect($rows)->max('depth') + 1),
            self::kpi('departments', $this->tenantId ? DB::table('tenant_departments')->where('tenant_id', $this->tenantId)->count() : 0),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->rows())
            ->columns([
                TextColumn::make('name')->label(self::col('branch'))->weight(fn (array $record) => $record['depth'] === 0 ? 'bold' : null),
                TextColumn::make('code')->label(self::col('code')),
                Columns::status('status', self::col('status')),
                TextColumn::make('manager')->label(self::col('manager')),
                TextColumn::make('staff')->label(self::col('staff'))->numeric(),
                TextColumn::make('departments')->label(self::col('departments'))->wrap(),
                TextColumn::make('timezone')->label(self::col('timezone')),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
