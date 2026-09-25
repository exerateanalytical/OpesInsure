<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierBrokerAgreements;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Components\RecordShell;
use App\Filament\Shared\Concerns\ListScreen;
use App\Models\CarrierBrokerAgreementRecord;
use BackedEnum;
use Filament\Actions;
use Filament\Infolists\Components\{RepeatableEntry, TextEntry};
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision D4 — contracts & delegated authority, READ-ONLY web view of
 * carrier-broker agreements (admin, insurer portal, broker portal). Drafting,
 * product permissions and activation stay in CarrierBrokerAgreementService via
 * /api/v1/platform/carrier-broker-agreements (maker-checker unchanged).
 * Visibility = CarrierBrokerAgreementController::index (platform tenant: all;
 * otherwise partner in tenant; insurer portal: + own carrier).
 */
final class CarrierBrokerAgreementResource extends Resource
{
    protected static ?string $model = CarrierBrokerAgreementRecord::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-handshake';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'agreements';

    public static function getNavigationLabel(): string { return __('web_experience.sections.agreements'); }

    public static function getModelLabel(): string { return __('web_experience.sections.agreement'); }

    public static function getPluralModelLabel(): string { return __('web_experience.sections.agreements'); }

    public static function getNavigationGroup(): ?string { return __('web_experience.sections.group_distribution'); }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasPermission('distribution.agreements.view');
    }

    public static function canCreate(): bool { return false; }

    public static function canEdit($record): bool { return false; }

    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->with(['carrier.party', 'partner.party']);
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);
        $platform = $tenant && DB::table('tenants')->where('id', $tenant)->value('type') === 'PLATFORM';

        return $platform && \App\Application\WebExperiences\PortalScope::panel() === null ? $q : $q->visibleInPortal();
    }

    public static function form(Schema $s): Schema { return $s->components([]); }

    public static function infolist(Schema $s): Schema
    {
        return $s->components([
            RecordShell::detailHeader(),
            Section::make(__('web_experience.sections.agreement'))->columns(['default' => 1, 'md' => 2])->schema([
                TextEntry::make('agreement_number')->label(__('web_experience.sections.number')),
                TextEntry::make('carrier.party.display_name')->label(__('web_experience.meta.carrier'))->placeholder('—'),
                TextEntry::make('partner.party.display_name')->label(__('web_experience.sections.broker'))->placeholder('—'),
                TextEntry::make('effective_from')->label(__('web_experience.sections.effective_from'))->date(),
                TextEntry::make('effective_until')->label(__('web_experience.sections.effective_until'))->date()->placeholder('—'),
                TextEntry::make('approved_at')->label(__('web_experience.sections.approved_at'))->dateTime()->placeholder('—'),
            ]),
            Section::make(__('web_experience.sections.products'))->schema([
                RepeatableEntry::make('products')->hiddenLabel()->columns(['default' => 1, 'md' => 4])->schema([
                    TextEntry::make('line_code')->label(__('web_experience.meta.line')),
                    TextEntry::make('can_quote')->label(__('web_experience.sections.can_quote'))->formatStateUsing(fn ($state) => $state ? __('web_experience.authority.yes') : __('web_experience.authority.no')),
                    TextEntry::make('can_bind')->label(__('web_experience.sections.can_bind'))->formatStateUsing(fn ($state) => $state ? __('web_experience.authority.yes') : __('web_experience.authority.no')),
                    TextEntry::make('commission_basis_points')->label(__('web_experience.sections.commission_bp'))->placeholder('—'),
                ]),
            ]),
            RecordShell::timeline('carrier_broker_agreement'),
        ]);
    }

    public static function table(Table $t): Table
    {
        return ListScreen::apply($t->defaultSort('effective_from', 'desc')->columns([
            Tables\Columns\TextColumn::make('agreement_number')->label(__('web_experience.sections.number'))->searchable()->copyable(),
            Tables\Columns\TextColumn::make('carrier.party.display_name')->label(__('web_experience.meta.carrier')),
            Tables\Columns\TextColumn::make('partner.party.display_name')->label(__('web_experience.sections.broker')),
            Tables\Columns\TextColumn::make('effective_from')->label(__('web_experience.sections.effective_from'))->date(),
            Tables\Columns\TextColumn::make('effective_until')->label(__('web_experience.sections.effective_until'))->date()->placeholder('—'),
            Tables\Columns\TextColumn::make('status')->label(__('web_experience.status.label'))->badge()->color(fn (string $state) => \App\Application\WebExperiences\RecordSummary::toneFor($state)),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(['DRAFT' => 'DRAFT', 'ACTIVE' => 'ACTIVE', 'SUSPENDED' => 'SUSPENDED', 'TERMINATED' => 'TERMINATED', 'EXPIRED' => 'EXPIRED']),
        ])->recordActions([Actions\ViewAction::make()]), 'agreements');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCarrierBrokerAgreements::route('/'), 'view' => Pages\ViewCarrierBrokerAgreement::route('/{record}')];
    }
}
