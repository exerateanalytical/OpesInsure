<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Application\WebExperiences\PortalScope;
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
use Livewire\Attributes\Locked;

/**
 * Insurer-side health work screens (pre-authorization queue, provider claim adjudication, provider settlement batches).
 * Rows come from the SAME service list the API returns (no query logic here); each row opens a read-only detail (the
 * service's detail payload: lines + history) and carries the shared HealthProviderActions, so every decision runs through
 * the API's service and permission. The tenant is captured at mount and re-applied on each Livewire round-trip.
 */
abstract class HealthQueuePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-heart';

    /** Permission gating the screen (the API's list permission). */
    protected static string $permission = 'health.preauth.view';

    /** workflow_actions.screens.<key> */
    protected static string $screen = 'preauth_queue';

    /** @var list<string> */
    protected const STATUSES = [];

    /** Table the rows come from; the insurer panel narrows it to the caller's carrier (PortalScope::visibleOf). */
    protected const TABLE = 'health_preauthorizations';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        return WorkflowAction::allowed(static::$permission);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('workflow_actions.screens.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('workflow_actions.screens.'.static::$screen);
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

    /** @return list<object> service rows for the status filter */
    abstract protected function fetch(string $tenantId, ?string $status): array;

    /** @return list<string> row keys shown as columns */
    abstract protected function columns(): array;

    /** @return list<Action> workflow actions for a row */
    abstract protected function workflowActions(): array;

    /** @return array{cards: array<string, mixed>, lines: list<array<string, mixed>>, history: list<array<string, mixed>>} */
    abstract protected function detail(string $tenantId, string $id): array;

    /** @return list<Action> */
    protected function headerWorkflowActions(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        $cols = array_map(fn (string $c) => ($c === 'status' ? TextColumn::make($c)->badge() : TextColumn::make($c))->label(str_replace('_', ' ', ucfirst($c))), $this->columns());

        return $table
            ->records(function (array $filters): array {
                if ($this->tenantId === null) {
                    return [];
                }
                $rows = array_map(fn ($r) => (array) $r, $this->fetch($this->tenantId, $filters['status']['value'] ?? null));
                // Insurer panel: only the caller's carrier's rows (owner decision 2026-09-27, PortalScope).
                $visible = array_flip(PortalScope::visibleOf(static::TABLE, array_map('strval', array_column($rows, 'id'))));
                $rows = array_values(array_filter($rows, fn ($r) => isset($visible[(string) $r['id']])));
                $names = $this->providerNames(array_column($rows, 'provider_profile_id'));

                return collect($rows)->mapWithKeys(fn ($r) => [$r['id'] => ['__key' => $r['id'], 'provider' => $names[$r['provider_profile_id'] ?? ''] ?? null] + $r])->all();
            })
            ->columns([...$cols])
            ->filters(static::STATUSES === [] ? [] : [SelectFilter::make('status')->options(array_combine(static::STATUSES, static::STATUSES))])
            ->headerActions($this->headerWorkflowActions())
            ->recordActions([
                Action::make('viewDetail')->label(__('workflow_actions.viewDetail.label'))->icon('lucide-eye')->slideOver()
                    ->modalHeading(fn (array $record) => __('workflow_actions.screens.'.static::$screen).' — '.($record[$this->columns()[0]] ?? ''))
                    ->modalSubmitAction(false)
                    ->modalContent(fn (array $record) => view('filament.shared.pages.health-detail', $this->detail((string) $this->tenantId, static::assertVisible((string) $record['id'])))),
                ...$this->workflowActions(),
            ])
            ->emptyStateHeading(__('web_experience.list.empty_heading'));
    }

    /** An id outside the caller's carrier scope is a 404, never a detail or a decision. */
    public static function assertVisible(string $id): string
    {
        abort_if(PortalScope::visibleOf(static::TABLE, [$id]) === [], 404);

        return $id;
    }

    /** @param list<?string> $ids @return array<string, string> provider_profile_id => party display name */
    protected function providerNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : DB::table('provider_profiles')->join('parties', 'parties.id', '=', 'provider_profiles.party_id')
            ->whereIn('provider_profiles.id', $ids)->pluck('parties.display_name', 'provider_profiles.id')->all();
    }

    /** @param iterable<object|array> $rows @return list<array<string, mixed>> scalar-only rows (JSON columns rendered as text) */
    protected static function flat(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = array_map(fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v, (array) $r);
        }

        return $out;
    }
}
