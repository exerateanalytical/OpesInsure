<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Insurer;

use App\Application\Rules\Models\RuleSet;
use App\Application\WebExperiences\PortalAuthorization;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\RuleSetActions;
use App\Filament\Shared\Columns;
use App\Models\InsuranceProduct;
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
 * CAR-018 Product eligibility rules (WF-017, REQ-RUL-002): the ELIGIBILITY rule sets of the insurer's own product
 * versions (GET rule-sets, rules.view), each with its ordered rules (priority, stop, outcome). Governance and the test
 * pane are the existing rule-set actions (RuleSetActions submit / approve / reject / retire / simulate — same service,
 * permissions and maker-checker as the API; own product checked by PortalScope::isOwnRecord). Rule sets are authored
 * in the API / admin rule-set screen; a portal author cannot target another carrier's product from here.
 */
final class EligibilityRules extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    protected static ?string $slug = 'eligibility-rules';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-list-checks';

    protected static ?int $navigationSort = 33;

    public const DOMAIN = 'ELIGIBILITY';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && PortalScope::panel() === 'insurer' && rescue(fn () => app(TenantContext::class)->id(), null, false) !== null
            && PortalAuthorization::allowsRead($u, 'rules.view');
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Products & pricing';
    }

    public static function getNavigationLabel(): string
    {
        return __('insurer_screens.eligibility.title');
    }

    public function getTitle(): string
    {
        return __('insurer_screens.eligibility.title');
    }

    public function getSubheading(): ?string
    {
        return __('insurer_screens.eligibility.subheading');
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

    /** Own carrier's product versions (no carrier = none, as InsuranceProductResource in /insurer). */
    public static function ownProducts(): Builder
    {
        $carrier = PortalScope::carrierId();

        return $carrier === null ? InsuranceProduct::query()->whereRaw('1 = 0') : InsuranceProduct::query()->where('carrier_id', $carrier);
    }

    public function table(Table $table): Table
    {
        $l = fn (string $k) => __('insurer_screens.rules.columns.'.$k);
        $products = fn () => self::ownProducts()->orderBy('name')->get(['id', 'name', 'version'])->mapWithKeys(fn ($p) => [$p->id => $p->name.' v'.$p->version])->all();

        return $table
            ->query(fn () => RuleSet::query()->with('rules')->where('domain', static::DOMAIN)->whereIn('insurance_product_id', self::ownProducts()->select('id')))
            ->defaultSort('code')
            ->columns([
                TextColumn::make('product')->label($l('product'))->state(fn (RuleSet $r) => $products()[$r->insurance_product_id] ?? '—')->weight('medium'),
                Columns::text('code', $l('code'))->searchable(),
                TextColumn::make('version')->label($l('version'))->formatStateUsing(fn ($s) => 'v'.$s),
                Columns::status('status', $l('status')),
                Columns::date('effective_from', false, $l('effective_from')),
                TextColumn::make('rules_summary')->label($l('rules'))->wrap()->listWithLineBreaks()
                    ->state(fn (RuleSet $r) => $r->rules->sortBy('priority')->map(fn ($rule) => $rule->priority.'. '.$rule->code.' → '
                        .(is_array($rule->outcome) ? (string) ($rule->outcome['result'] ?? $rule->outcome['outcome'] ?? json_encode($rule->outcome)) : (string) $rule->outcome)
                        .($rule->stop_processing ? ' ⏹' : '').($rule->enabled === false ? ' ('.__('insurer_screens.rules.disabled').')' : ''))->values()->all()),
            ])
            ->filters([
                SelectFilter::make('insurance_product_id')->label($l('product'))->options($products),
                SelectFilter::make('status')->label($l('status'))->options(collect(['DRAFT', 'IN_REVIEW', 'APPROVED', 'REJECTED', 'RETIRED'])->mapWithKeys(fn ($s) => [$s => Columns::humanise($s)])->all()),
            ])
            ->recordActions([RuleSetActions::ruleSetSimulate(), RuleSetActions::ruleSetSubmit(), RuleSetActions::ruleSetApprove(),
                RuleSetActions::ruleSetReject(), RuleSetActions::ruleSetRetire()])
            ->emptyStateHeading(__('insurer_screens.rules.empty'));
    }
}
