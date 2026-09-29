<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\WorkflowAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Staff register screens over governance tables that have no Eloquent model (document intake, retention schedules,
 * legal holds, destruction requests, signature requests; fiscal power records, conflicts, stamp duty schedules,
 * transport licences). Rows are read with the same tenant scoping the API list uses; every change goes through the
 * shared workflow actions (MasterDataGovernance / DocumentGovernance / VehiclePower actions), which call the API's service
 * with the API's permission. The tenant is captured at mount and re-applied on each Livewire round-trip.
 */
abstract class GovernanceRegisterPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-folder-cog';

    /** Permission(s) gating the screen: any one of them opens it (the API's list / action permissions). */
    protected static array $permissions = [];

    /** masterdata_actions.screens.<key> */
    protected static string $screen = '';

    /** masterdata_actions.screens.groups.<key> */
    protected static string $group = 'documents';

    /** @var list<string> */
    protected const STATUSES = [];

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        foreach (static::$permissions as $p) {
            if (WorkflowAction::allowed($p)) {
                return true;
            }
        }

        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('masterdata_actions.screens.groups.'.static::$group);
    }

    public static function getNavigationLabel(): string
    {
        return __('masterdata_actions.screens.'.static::$screen);
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->tenantId = rescue(fn () => app(TenantContext::class)->id(), null, false);
    }

    public function hydrate(): void
    {
        if ($this->tenantId !== null) {
            app(TenantContext::class)->set($this->tenantId);
        }
    }

    /** @return \Illuminate\Database\Query\Builder rows visible to the tenant */
    abstract protected function query(string $tenantId);

    /** @return list<string> */
    abstract protected function columns(): array;

    /** @return list<Action> */
    protected function headerWorkflowActions(): array
    {
        return [];
    }

    /** @return list<Action> */
    protected function recordWorkflowActions(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters = null): array {
                if ($this->tenantId === null) {
                    return [];
                }
                $q = $this->query($this->tenantId);
                if (filled($status = $filters['status']['value'] ?? null)) {
                    $q->where('status', $status);
                }

                return $q->limit(200)->get()->mapWithKeys(fn ($r) => [$r->id => array_map(fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v, (array) $r)])->all();
            })
            ->columns(array_map(fn (string $c) => TextColumn::make($c)->label(WorkflowAction::optional("masterdata_actions.fields.{$c}") ?? Str::ucfirst(str_replace('_', ' ', $c)))
                ->badge($c === 'status' || str_ends_with($c, '_state'))->placeholder('—'), $this->columns()))
            ->filters(static::STATUSES === [] ? [] : [SelectFilter::make('status')->options(array_combine(static::STATUSES, static::STATUSES))])
            ->headerActions($this->headerWorkflowActions())
            ->recordActions($this->recordWorkflowActions())
            ->emptyStateHeading(__('web_experience.list.empty_heading'));
    }

    /** Row lookup restricted to the tenant's rows (a row outside the register is a 404). */
    public static function row(string $table, string $id, ?string $tenantId, bool $platformRows = false): object
    {
        return DB::table($table)->where('id', $id)
            ->where(fn ($q) => $platformRows ? $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id') : $q->where('tenant_id', $tenantId))
            ->first() ?? abort(404);
    }
}
