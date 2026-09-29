<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Identity\Rbac\PlatformAuthority;
use App\Application\Settings\FeatureFlags;
use App\Filament\Shared\Actions\WorkflowAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * ADM-030 Feature flags — GET/PUT admin/feature-flags (tenant.manage) via FeatureFlags::set. Same rule as the API:
 * the platform tenant sees and sets every scope; any other organisation only its own tenant (and its branches).
 */
final class FeatureFlagsScreen extends AdminScreenPage
{
    public const P = 'tenant.manage';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-toggle-left';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'platform/feature-flags';

    protected static array $permissions = [self::P];

    protected static string $screen = 'feature_flags';

    protected static string $group = 'Administration';

    private function isPlatform(): bool
    {
        return (bool) rescue(fn () => app(PlatformAuthority::class)->isPlatformTenant($this->tenantId), false, false);
    }

    private function flags(): \Illuminate\Database\Query\Builder
    {
        return DB::table('feature_flags')->when(! $this->isPlatform(), fn ($q) => $q->where('tenant_id', $this->tenantId ?? '00000000-0000-0000-0000-000000000000'));
    }

    public function kpis(): array
    {
        return [
            self::kpi('flags', (clone $this->flags())->distinct()->count('key')),
            self::kpi('enabled', (clone $this->flags())->where('enabled', true)->count(), 'success'),
            self::kpi('disabled', (clone $this->flags())->where('enabled', false)->count()),
            self::kpi('scoped_rules', (clone $this->flags())->count()),
        ];
    }

    /** Applies the API's scope rule, then FeatureFlags::set (validates key, branch ownership, audits). */
    public function setFlag(array $data): object
    {
        abort_unless(WorkflowAction::allowed(self::P), 403);
        $d = array_intersect_key($data, array_flip(['key', 'enabled', 'environment', 'country_code', 'tenant_id', 'branch_id', 'product_code', 'description']));
        if (! $this->isPlatform()) {
            abort_if(! empty($d['tenant_id']) && $d['tenant_id'] !== $this->tenantId, 403);
            $d['tenant_id'] = $this->tenantId;
        }
        if (! empty($d['branch_id'])) {
            abort_unless(DB::table('tenant_branches')->where('id', $d['branch_id'])->where('tenant_id', $d['tenant_id'] ?? $this->tenantId)->exists(), 404);
        }

        return app(FeatureFlags::class)->set($d, (string) auth()->id());
    }

    protected function getHeaderActions(): array
    {
        return [
            WorkflowAction::make('flagSet', self::P, 'admin_screens')->icon('lucide-plus')
                ->schema([
                    TextInput::make('key')->label(self::col('flag_key'))->required()->maxLength(96)->regex('/^[a-z0-9][a-z0-9._-]{1,95}$/'),
                    Toggle::make('enabled')->label(self::col('enabled'))->default(true),
                    Select::make('branch_id')->label(self::col('branch'))
                        ->options(fn () => DB::table('tenant_branches')->where('tenant_id', $this->tenantId)->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all()),
                    TextInput::make('product_code')->label(self::col('product_code'))->maxLength(64),
                    TextInput::make('environment')->label(self::col('environment'))->maxLength(24),
                    Textarea::make('description')->label(self::col('description'))->maxLength(500),
                ])
                ->action(fn (Action $action, array $data) => WorkflowAction::run($action, self::P, fn () => $this->setFlag($data), __('admin_screens.flagSet.done'))),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->deferLoading()
            ->records(function (?string $search, int|string $page, int|string $recordsPerPage) {
                $branches = DB::table('tenant_branches')->pluck('name', 'id');
                $tenants = $this->isPlatform() ? DB::table('tenants')->pluck('legal_name', 'id') : collect();
                $rows = [];
                foreach ((clone $this->flags())->orderBy('key')->limit(1000)->get() as $f) {
                    $rows[$f->id] = [
                        '__key' => $f->id, 'id' => $f->id, 'key' => $f->key, 'enabled' => (bool) $f->enabled,
                        'scope' => collect([
                            $f->environment, $f->country_code, $f->tenant_id ? ($tenants[$f->tenant_id] ?? __('admin_screens.this_organisation')) : null,
                            $f->branch_id ? ($branches[$f->branch_id] ?? '—') : null, $f->product_code,
                        ])->filter()->implode(' · ') ?: __('admin_screens.global'),
                        'tenant_id' => $f->tenant_id, 'branch_id' => $f->branch_id, 'environment' => $f->environment, 'country_code' => $f->country_code, 'product_code' => $f->product_code,
                        'description' => $f->description ?? '—', 'updated_at' => $f->updated_at,
                    ];
                }

                return self::pageOf($rows, $search, ['key', 'description', 'scope'], $page, $recordsPerPage);
            })
            ->columns([
                TextColumn::make('key')->label(self::col('flag_key'))->searchable()->fontFamily('mono'),
                IconColumn::make('enabled')->label(self::col('enabled'))->boolean(),
                TextColumn::make('scope')->label(self::col('scope'))->wrap(),
                TextColumn::make('description')->label(self::col('description'))->wrap()->limit(100),
                TextColumn::make('updated_at')->label(self::col('updated_at'))->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([
                WorkflowAction::make('flagToggle', self::P, 'admin_screens')->icon('lucide-toggle-right')->requiresConfirmation()
                    ->visible(fn (array $record) => $this->isPlatform() || $record['tenant_id'] === $this->tenantId)
                    ->action(fn (Action $action, array $record) => WorkflowAction::run($action, self::P, fn () => $this->setFlag([
                        'key' => $record['key'], 'enabled' => ! $record['enabled'], 'tenant_id' => $record['tenant_id'], 'branch_id' => $record['branch_id'],
                        'environment' => $record['environment'], 'country_code' => $record['country_code'], 'product_code' => $record['product_code'],
                    ]), __('admin_screens.flagToggle.done'))),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
