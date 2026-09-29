<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Insurer;

use App\Application\WebExperiences\InsurerDashboards;
use App\Application\WebExperiences\Money;
use App\Application\WebExperiences\PortalAuthorization;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\CarrierBrokerAgreements\CarrierBrokerAgreementResource;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

/**
 * CAR-012 Agent / intermediary overview: the carrier's brokers and agents with their licence, delegated authority and
 * production — the carrier workspace API (GET mobile/partner/carrier/partners, carrier.dashboard.read), own carrier
 * only. A row opens the carrier–broker agreement (CAR-011, CarrierBrokerAgreementResource view) when one exists.
 */
final class IntermediaryOverview extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    protected static ?string $slug = 'intermediaries';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-users-round';

    protected static ?int $navigationSort = 12;

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && PortalScope::panel() === 'insurer' && rescue(fn () => app(TenantContext::class)->id(), null, false) !== null
            && PortalAuthorization::allowsRead($u, 'carrier.dashboard.read');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('web_experience.sections.group_distribution');
    }

    public static function getNavigationLabel(): string
    {
        return __('insurer_screens.intermediaries.title');
    }

    public function getTitle(): string
    {
        return __('insurer_screens.intermediaries.title');
    }

    public function getSubheading(): ?string
    {
        return __('insurer_screens.intermediaries.subheading');
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

    /** @return array<string, array<string, mixed>> */
    public function rows(): array
    {
        if ($this->tenantId === null) {
            return [];
        }
        $svc = app(InsurerDashboards::class);
        $ccy = $svc->currency();
        $rows = $svc->intermediaries();
        $carrier = PortalScope::carrierId();
        $agreements = $carrier === null ? collect() : DB::table('carrier_broker_agreements')->where('carrier_id', $carrier)
            ->whereIn('partner_id', array_column($rows, 'id'))->orderByDesc('effective_from')->get(['id', 'partner_id'])->unique('partner_id')->pluck('id', 'partner_id');
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['id']] = ['__key' => (string) $r['id'], 'premium' => Money::format((int) $r['premium_minor'], $ccy), 'agreement_id' => $agreements[$r['id']] ?? null] + $r;
        }

        return $out;
    }

    public function table(Table $table): Table
    {
        $c = fn (string $name) => TextColumn::make($name)->label(__('insurer_screens.columns.'.$name))->placeholder('—');

        return $table
            ->records(fn (): array => $this->rows())
            ->columns([$c('name')->weight('medium')->searchable(), $c('type')->badge(), $c('status')->badge(), $c('licence_number'),
                $c('agreement_number'), $c('agreement_status')->badge(), $c('policies')->numeric(), $c('premium')])
            ->recordUrl(fn (array $record) => $record['agreement_id'] && CarrierBrokerAgreementResource::canViewAny() ?CarrierBrokerAgreementResource::getUrl('view', ['record' => $record['agreement_id']]) : null)
            ->paginated(false)
            ->emptyStateHeading(__('insurer_screens.empty'));
    }
}
