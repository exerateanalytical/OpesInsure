<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Insurer;

use App\Application\WebExperiences\PortalAuthorization;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\TariffVersions\TariffVersionResource;
use App\Filament\Shared\Actions\TariffActions;
use App\Filament\Shared\Columns;
use App\Models\TariffVersion;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * CAR-022 Rating rules (WF-011): the rating factors (base rates, loadings, discounts, caps) of every controlled tariff
 * version of the insurer's own products, rule by rule, with the tariff lifecycle (TariffActions: submit / approve /
 * reject / schedule / activate / expire — TariffGovernanceService, tariff.manage / tariff.approve, maker-checker and
 * rules-hash check). Read gate: the carrier product/tariff read (GET mobile/partner/carrier/products,
 * carrier.dashboard.read) or a tariff permission. A new tariff version is created in the tariff register.
 */
final class RatingRules extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    protected static ?string $slug = 'rating-rules';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-sliders-horizontal';

    protected static ?int $navigationSort = 34;

    public const READS = ['carrier.dashboard.read', 'tariff.manage', 'tariff.approve'];

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
        return 'Products & pricing';
    }

    public static function getNavigationLabel(): string
    {
        return __('insurer_screens.rating.title');
    }

    public function getTitle(): string
    {
        return __('insurer_screens.rating.title');
    }

    public function getSubheading(): ?string
    {
        return __('insurer_screens.rating.subheading');
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

    /** @return list<string> "factor: value" lines of a tariff's rules (nested values shown as compact JSON). */
    public static function factors(mixed $rules): array
    {
        $rules = is_string($rules) ? (json_decode($rules, true) ?? []) : (array) $rules;
        $out = [];
        foreach ($rules as $k => $v) {
            $out[] = (is_int($k) ? '#'.($k + 1) : $k).': '.Str::limit(is_scalar($v) || $v === null ? var_export($v, true) : (string) json_encode($v, JSON_UNESCAPED_UNICODE), 120);
        }

        return $out;
    }

    public function table(Table $table): Table
    {
        $l = fn (string $k) => __('insurer_screens.rules.columns.'.$k);
        $products = fn () => EligibilityRules::ownProducts()->orderBy('name')->get(['id', 'name', 'version'])->mapWithKeys(fn ($p) => [$p->id => $p->name.' v'.$p->version])->all();

        return $table
            ->query(fn () => TariffVersion::query()->with('product')->whereIn('insurance_product_id', EligibilityRules::ownProducts()->select('id')))
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('product.name')->label($l('product'))->weight('medium')->searchable(),
                TextColumn::make('version')->label($l('version'))->formatStateUsing(fn ($s) => 'v'.$s),
                Columns::status('status', $l('status')),
                Columns::date('effective_from', false, $l('effective_from')),
                TextColumn::make('regulatory_reference')->label($l('reference'))->placeholder('—'),
                TextColumn::make('factors')->label($l('factors'))->wrap()->listWithLineBreaks()->limitList(8)->expandableLimitedList()
                    ->state(fn (TariffVersion $r) => self::factors($r->rules)),
                TextColumn::make('rules_hash')->label($l('hash'))->limit(12)->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('insurance_product_id')->label($l('product'))->options($products),
                SelectFilter::make('status')->label($l('status'))->options(collect(['DRAFT', 'SUBMITTED', 'APPROVED', 'SCHEDULED', 'ACTIVE', 'EXPIRED', 'REJECTED'])->mapWithKeys(fn ($s) => [$s => Columns::humanise($s)])->all()),
            ])
            ->recordActions([TariffActions::group()])
            ->recordUrl(fn (TariffVersion $r) => TariffVersionResource::canViewAny() ? TariffVersionResource::getUrl('view', ['record' => $r]) : null)
            ->emptyStateHeading(__('insurer_screens.rating.empty'));
    }
}
