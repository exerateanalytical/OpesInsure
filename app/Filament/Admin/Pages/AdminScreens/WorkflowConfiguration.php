<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Filament\Shared\Columns;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * ADM-028 Workflow configuration — read view of the configured workflows: each case type version (GET case-types,
 * cases.view) with its states, transitions, SLA policies and automatic tasks, and the work queues of this
 * organisation that route it (GET queues). Case types stay versioned and approved in Case types; nothing is edited here.
 */
final class WorkflowConfiguration extends AdminScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-workflow';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'cases/workflow-configuration';

    protected static array $permissions = ['cases.view'];

    protected static string $screen = 'workflow_configuration';

    protected static string $group = 'Cases & tasks';

    private static function json(mixed $v): array
    {
        return is_array($v) ? $v : (json_decode((string) $v, true) ?: []);
    }

    /** @return array<string, array<string, mixed>> */
    public function rows(): array
    {
        $queues = $this->tenantId === null ? collect() : DB::table('queues')->where('tenant_id', $this->tenantId)->where('active', true)->get(['code', 'name', 'case_type_codes']);
        $rows = [];
        foreach (DB::table('case_types')->orderBy('code')->orderByDesc('version')->get() as $t) {
            $states = self::json($t->states);
            $transitions = self::json($t->transitions);
            $routed = $queues->filter(fn ($q) => in_array($t->code, self::json($q->case_type_codes), true))->pluck('name')->implode(', ');
            $rows[$t->id] = [
                '__key' => $t->id, 'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'version' => $t->version, 'status' => $t->status,
                'family' => $t->family_code, 'states' => count($states), 'transitions' => count($transitions),
                'sla' => count(self::json($t->sla_policies)), 'auto_tasks' => count(self::json($t->auto_tasks)),
                'queues' => $routed ?: '—', 'regulated' => $t->regulated ? __('admin_screens.yes') : __('admin_screens.no'),
            ];
        }

        return $rows;
    }

    public function kpis(): array
    {
        $rows = collect($this->rows());

        return [
            self::kpi('case_types', $rows->pluck('code')->unique()->count()),
            self::kpi('active_versions', $rows->where('status', 'ACTIVE')->count()),
            self::kpi('queues', $this->tenantId ? DB::table('queues')->where('tenant_id', $this->tenantId)->where('active', true)->count() : 0),
            self::kpi('unrouted', $rows->where('status', 'ACTIVE')->where('queues', '—')->count(), null, __('admin_screens.kpis.unrouted_hint')),
        ];
    }

    /** States and transitions of one case type version (modal). */
    public static function flow(string $id): array
    {
        $t = DB::table('case_types')->where('id', $id)->first();
        if (! $t) {
            return ['states' => [], 'transitions' => [], 'sla' => []];
        }
        $label = fn ($v) => is_array($v) ? ($v['code'] ?? $v['name'] ?? json_encode($v)) : (string) $v;
        $transitions = [];
        foreach (self::json($t->transitions) as $k => $tr) {
            $list = fn ($v) => is_array($v) ? implode(' / ', array_map('strval', $v)) : (string) $v;
            $transitions[] = is_array($tr)
                ? trim((isset($tr['event']) ? $tr['event'].': ' : '').$list($tr['from'] ?? (is_string($k) ? $k : '?')).' → '.$list($tr['to'] ?? '?').(isset($tr['permission']) ? ' ['.$tr['permission'].']' : ''))
                : (is_string($k) ? $k.' → '.$list($tr) : $list($tr));
        }

        return [
            'states' => array_map($label, array_values(self::json($t->states))),
            'transitions' => $transitions,
            'sla' => collect(self::json($t->sla_policies))->map(fn ($v, $k) => (is_string($k) ? $k.': ' : '').(is_scalar($v) ? $v : json_encode($v)))->values()->all(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, int|string $page, int|string $recordsPerPage) => self::pageOf($this->rows(), $search, ['code', 'name', 'family', 'queues'], $page, $recordsPerPage))
            ->columns([
                TextColumn::make('name')->label(self::col('case_type'))->searchable()->description(fn (array $record) => $record['code'].' · v'.$record['version']),
                TextColumn::make('family')->label(self::col('family'))->placeholder('—'),
                Columns::status('status', self::col('status')),
                TextColumn::make('states')->label(self::col('states'))->numeric(),
                TextColumn::make('transitions')->label(self::col('transitions'))->numeric(),
                TextColumn::make('sla')->label(self::col('sla_policies'))->numeric(),
                TextColumn::make('auto_tasks')->label(self::col('auto_tasks'))->numeric(),
                TextColumn::make('queues')->label(self::col('queues'))->wrap(),
            ])
            ->recordActions([
                Action::make('workflowFlow')->label(__('admin_screens.workflowFlow.label'))->icon('lucide-eye')->color('gray')
                    ->modalHeading(fn (array $record) => $record['name'].' · v'.$record['version'])->modalSubmitAction(false)
                    ->modalContent(fn (array $record) => view('filament.admin.pages.partials.workflow-flow', self::flow($record['id']))),
            ])
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
