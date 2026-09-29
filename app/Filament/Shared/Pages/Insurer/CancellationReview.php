<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Insurer;

use App\Application\Policies\Cancellation\PolicyCancellation;
use App\Application\Policies\Http\PolicyCancellationController;
use App\Application\Policies\Suspension\PolicySuspension;
use App\Application\WebExperiences\PortalAuthorization;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Policies\PolicyResource;
use App\Filament\Shared\Actions\PolicyActions;
use App\Filament\Shared\Actions\PolicyServicingActions;
use App\Filament\Shared\Columns;
use App\Filament\Shared\Concerns\ListScreen;
use App\Models\Policy;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;

/**
 * CAR-029 Cancellation / suspension review (WF-045..047): the insurer's queue of policies with an open cancellation
 * request (GET policy-cancellations, policies.cancellation.review) or an open suspension / reinstatement request
 * (GET policy-reinstatement-queue, policies.reinstatement.approve) — own carrier's policies only (PortalScope).
 * The decisions are the existing policy actions (PolicyActions review / decide cancellation, PolicyServicingActions
 * request / decide reinstatement): same services, permissions and own-record checks as the API.
 */
final class CancellationReview extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    protected static ?string $slug = 'cancellation-review';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-file-x-2';

    protected static ?int $navigationSort = 25;

    public const READS = ['policies.cancellation.review', 'policies.cancellation.approve', 'policies.reinstatement.approve'];

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && PortalScope::panel() === 'insurer' && rescue(fn () => app(TenantContext::class)->id(), null, false) !== null
            && collect(self::READS)->contains(fn (string $p) => PortalAuthorization::allowsRead($u, $p));
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Policy operations';
    }

    public static function getNavigationLabel(): string
    {
        return __('insurer_screens.cancellations.title');
    }

    public function getTitle(): string
    {
        return __('insurer_screens.cancellations.title');
    }

    public function getSubheading(): ?string
    {
        return __('insurer_screens.cancellations.subheading');
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

    /** Own carrier's policies with an open cancellation or suspension episode. */
    public function query(): Builder
    {
        $t = (string) $this->tenantId;
        $u = auth()->user();
        $cancel = $u instanceof User && (PortalAuthorization::allowsRead($u, 'policies.cancellation.review') || PortalAuthorization::allowsRead($u, 'policies.cancellation.approve'));
        $suspend = $u instanceof User && PortalAuthorization::allowsRead($u, 'policies.reinstatement.approve');

        $q = Policy::query()->where('policies.tenant_id', $t)->where(function (Builder $w) use ($t, $cancel, $suspend) {
            $w->whereRaw('1 = 0');
            if ($cancel) {
                $w->orWhereIn('policies.id', PolicyCancellation::query()->where('tenant_id', $t)->whereIn('status', PolicyCancellationController::QUEUE_STATES)->select('policy_id'));
            }
            if ($suspend) {
                $w->orWhereIn('policies.id', PolicySuspension::query()->where('tenant_id', $t)->whereIn('status', PolicySuspension::OPEN_STATES)->select('policy_id'));
            }
        });

        return PortalScope::narrowTable($q, 'policies');
    }

    private static function openCancellation(Policy $p): ?PolicyCancellation
    {
        return PolicyCancellation::where('policy_id', $p->id)->where('tenant_id', $p->tenant_id)->whereIn('status', PolicyCancellationController::QUEUE_STATES)->latest('created_at')->first();
    }

    private static function openSuspension(Policy $p): ?PolicySuspension
    {
        return PolicySuspension::where('policy_id', $p->id)->where('tenant_id', $p->tenant_id)->whereIn('status', PolicySuspension::OPEN_STATES)->latest('created_at')->first();
    }

    public function table(Table $table): Table
    {
        $l = fn (string $k) => __('insurer_screens.cancellations.columns.'.$k);

        return ListScreen::apply($table
            ->query(fn () => $this->query())
            ->defaultSort('policies.updated_at', 'desc')
            ->columns([
                Columns::text('policy_number', $l('policy'))->searchable()->weight('medium'),
                Columns::status('status', $l('policy_status')),
                TextColumn::make('request_type')->label($l('request'))->badge()
                    ->state(fn (Policy $r) => self::openCancellation($r) ? __('insurer_screens.cancellations.types.cancellation') : __('insurer_screens.cancellations.types.suspension')),
                TextColumn::make('request_status')->label($l('request_status'))->badge()
                    ->state(fn (Policy $r) => Columns::humanise((self::openCancellation($r) ?? self::openSuspension($r))?->status)),
                TextColumn::make('reason')->label($l('reason'))->placeholder('—')
                    ->state(fn (Policy $r) => ($c = self::openCancellation($r)) ? $c->reason_code : self::openSuspension($r)?->reason_code),
                TextColumn::make('effective')->label($l('effective'))->placeholder('—')
                    ->state(fn (Policy $r) => optional(self::openCancellation($r)?->effective_at ?? self::openSuspension($r)?->suspended_at)->format('d/m/Y')),
                TextColumn::make('refund')->label($l('refund'))->placeholder('—')
                    ->state(fn (Policy $r) => ($c = self::openCancellation($r)) && $c->refund_minor !== null ? \App\Application\WebExperiences\Money::format((int) $c->refund_minor, $c->currency) : null),
            ])
            ->filters([SelectFilter::make('type')->label($l('request'))->options([
                'cancellation' => __('insurer_screens.cancellations.types.cancellation'), 'suspension' => __('insurer_screens.cancellations.types.suspension'),
            ])->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                'cancellation' => $q->whereIn('policies.id', PolicyCancellation::query()->whereIn('status', PolicyCancellationController::QUEUE_STATES)->select('policy_id')),
                'suspension' => $q->whereIn('policies.id', PolicySuspension::query()->whereIn('status', PolicySuspension::OPEN_STATES)->select('policy_id')),
                default => $q,
            })])
            ->recordActions([PolicyActions::reviewCancellation(), PolicyActions::decideCancellation(),
                PolicyServicingActions::requestReinstatement(), PolicyServicingActions::decideReinstatement()])
            ->recordUrl(fn (Policy $r) => PolicyResource::getUrl('view', ['record' => $r]))
            ->emptyStateHeading(__('insurer_screens.cancellations.empty')), 'cancellation-review');
    }
}
