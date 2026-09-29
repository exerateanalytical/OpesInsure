<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Application\WebExperiences\{PortalAuthorization, PortalScope};
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Columns;
use App\Filament\Shared\Concerns\ListScreen;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

/**
 * Launch 2026-10-02, docs/LAUNCH_SCREEN_GAPS_2026-09-29.md §5 (BRK-028..088, second part): one /broker work screen =
 * a book-scoped queue (and, for dashboards, KPI tiles over the SAME record set) with the shared workflow actions.
 * docs/spec/PORTAL_WRITE_RULES.md:
 *  - read gate: PortalAuthorization::allowsRead($user, static::$readPermission) (the API route's read permission);
 *  - rows: portal tenant + PortalScope::narrowTable (the caller's book, same rule as the Quote / Policy / Claim lists);
 *  - writes: only existing WorkflowAction-based actions (permission of the API route + PortalScope::isOwnRecord).
 * Subclasses declare the lang key, the query, the columns and the actions; labels live in broker_screens_b.php.
 */
abstract class BrokerScreen extends Page implements HasTable
{
    use InteractsWithTable;

    /** The /broker screens of this family, registered by BrokerPanelProvider (BRK id in each class docblock). */
    public const PAGES = [
        ProductEligibilityRulesPage::class, QuoteDashboardPage::class, ExceptionalQuoteReviewPage::class, PremiumOverrideApprovalPage::class,
        QuoteConversionAnalyticsPage::class, ProposalCompletenessReviewPage::class, InformationRequestPage::class, ConditionalOfferReviewPage::class,
        DeclinedProposalReviewPage::class, PaymentDashboardPage::class, PendingPaymentsPage::class, FailedPaymentsPage::class,
        DuplicatePaymentReviewPage::class, PolicyDashboardPage::class, FailedIssuanceQueuePage::class, CancellationQueuePage::class,
        SuspensionReinstatementQueuePage::class, RenewalAssignmentPage::class, LapsedPoliciesPage::class, PaidRenewalIssuanceExceptionsPage::class,
        DocumentGenerationQueuePage::class, RevokedDocumentsPage::class, StickerAllocationPage::class, StickerReconciliationPage::class,
        CoverageReviewPage::class, EvidenceReviewPage::class, ExpertAssignmentPage::class, AssessmentReviewPage::class,
        ClaimInvestigationPage::class, SettlementPreparationPage::class, ClaimAppealPage::class, ClaimReopeningPage::class,
    ];

    protected string $view = 'filament.shared.pages.broker-screen';

    /** Key in lang/{en,fr}/broker_screens_b.php (nav, title, subtitle, empty). */
    protected static string $key = '';

    /** @var list<string> any one grants the read (checked with PortalAuthorization::allowsRead) */
    protected static array $readPermissions = ['broker.portal.read'];

    protected static ?string $group = 'Sales workspace';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return PortalScope::panel() === 'broker' && $user instanceof User
            && rescue(fn () => app(TenantContext::class)->id(), null, false) !== null
            && collect(static::$readPermissions)->contains(fn (string $p): bool => PortalAuthorization::allowsRead($user, $p));
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$group;
    }

    public static function getNavigationLabel(): string
    {
        return __('broker_screens_b.'.static::$key.'.nav');
    }

    public function getTitle(): string
    {
        return __('broker_screens_b.'.static::$key.'.title');
    }

    public function getSubheading(): ?string
    {
        return __('broker_screens_b.'.static::$key.'.subtitle');
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

    protected function tenant(): string
    {
        return (string) ($this->tenantId ?? app(TenantContext::class)->id() ?? '00000000-0000-0000-0000-000000000000');
    }

    /** $model rows of the portal tenant inside the caller's book ($table must be known to PortalScope::narrowTable). */
    protected function book(string $model, string $table): Builder
    {
        /** @var Builder $q */
        $q = $model::query()->where($table.'.tenant_id', $this->tenant());

        return PortalScope::narrowTable($q, $table);
    }

    /** Sub-select of the ids of $table visible to the caller (tenant + book). */
    protected function visibleIds(string $table): \Illuminate\Database\Query\Builder
    {
        return PortalScope::narrowTable(DB::table($table)->where($table.'.tenant_id', $this->tenant()), $table)->select($table.'.id');
    }

    abstract protected function query(): Builder;

    /** @return list<\Filament\Tables\Columns\Column> */
    abstract protected function columns(): array;

    /** @return list<\Filament\Actions\Action|\Filament\Actions\ActionGroup> */
    protected function recordActions(): array
    {
        return [];
    }

    /** @return list<\Filament\Actions\Action> */
    protected function headerActions(): array
    {
        return [];
    }

    /** @return list<\Filament\Tables\Filters\BaseFilter> */
    protected function filters(): array
    {
        return [];
    }

    protected function defaultSort(): string
    {
        return 'created_at';
    }

    /** Detail page of a row (the resource view page in /broker), or null. */
    protected function recordLink(Model $record): ?string
    {
        return null;
    }

    /**
     * KPI tiles shown above the queue (dashboards). Computed on the same scoped record sets as the lists.
     *
     * @return list<array{label: string, value: string|int, tone?: string}>
     */
    public function kpis(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return ListScreen::apply($table
            ->query(fn () => $this->query())
            ->defaultSort($this->defaultSort(), 'desc')
            ->columns($this->columns())
            ->filters($this->filters())
            ->headerActions($this->headerActions())
            ->recordActions($this->recordActions())
            ->recordUrl(fn (Model $record): ?string => rescue(fn () => $this->recordLink($record), null, false)), 'broker-'.static::$key);
    }

    // ---- shared helpers -------------------------------------------------------------------------------------------

    protected static function col(string $key): string
    {
        return __('broker_screens_b.columns.'.$key);
    }

    protected static function text(string $name, string $label): TextColumn
    {
        return Columns::text($name, self::col($label));
    }

    protected static function status(string $name = 'status', string $label = 'status'): TextColumn
    {
        return Columns::status($name, self::col($label));
    }

    protected static function date(string $name, string $label, bool $time = true): TextColumn
    {
        return Columns::date($name, $time, self::col($label));
    }

    protected static function money(string $name, string $label, string $currency = 'currency'): TextColumn
    {
        return Columns::money($name, $currency, self::col($label));
    }

    protected static function kpi(string $key, string|int $value, string $tone = 'gray'): array
    {
        return ['label' => __('broker_screens_b.kpis.'.$key), 'value' => $value, 'tone' => $tone];
    }

    protected static function viewUrl(string $resource, Model $record): ?string
    {
        return rescue(fn () => $resource::getUrl('view', ['record' => $record]), null, false);
    }
}
