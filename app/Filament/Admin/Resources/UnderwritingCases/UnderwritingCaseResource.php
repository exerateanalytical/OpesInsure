<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UnderwritingCases;

use App\Application\Underwriting\Workbench\UnderwritingWorkbenchQuery as Q;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\UnderwritingCases\Pages;
use App\Models\UnderwritingCase;
use BackedEnum;
use Closure;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Underwriting cases (/admin and /insurer). Q8 workbench (UND-005 case view): the detail page carries one tab per
 * underwriting screen (UND-006..016, 019) fed by UnderwritingWorkbenchQuery; actions stay in UnderwritingCaseActions.
 */
final class UnderwritingCaseResource extends \App\Filament\Shared\LocalizedResource
{
    protected static ?string $model = UnderwritingCase::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-users-round';

    protected static string|\UnitEnum|null $navigationGroup = 'Underwriting';

    protected static ?int $navigationSort = 52;

    public static function getEloquentQuery(): Builder
    {
        return \App\Application\WebExperiences\PortalScope::narrowToCarrier(parent::getEloquentQuery()->where('tenant_id', app(TenantContext::class)->id()))
            ->withCount(['referrals' => fn ($query) => $query->where('status', 'OPEN')]);
    }

    public static function form(Schema $s): Schema
    {
        return $s->components([]);
    }

    /** One workbench tab rendering a UnderwritingWorkbenchQuery section. @param Closure(UnderwritingCase): array $data */
    private static function tab(string $key, Closure $data): Tabs\Tab
    {
        return Tabs\Tab::make(__('uw_workbench.tabs.'.$key))->schema([
            View::make('filament.shared.uw-workbench-section')->viewData(fn (?UnderwritingCase $record) => ['key' => $key, 'section' => $record ? $data($record) : []])->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $s): Schema
    {
        $e = fn (string $path, string $key) => \Filament\Infolists\Components\TextEntry::make($path)->label(__('uw_workbench.f.'.$key))->placeholder('—');
        $overview = [\Filament\Schemas\Components\Section::make(__('uw_workbench.sections.case'))->columns(3)->columnSpanFull()->schema([
            $e('proposal.proposal_number', 'proposal')->copyable(), $e('status', 'status')->badge()->color(fn ($state) => \App\Filament\Shared\Components\RecordInfolist::color($state)),
            $e('proposal.party.display_name', 'applicant'), $e('carrier.cima_code', 'carrier'), $e('priority', 'priority'), $e('recommendation', 'recommendation'),
            $e('risk_score', 'risk_score'), $e('risk_band', 'risk_band'), $e('decision_due_at', 'decision_due_at')->dateTime(),
        ])];
        $viewer = fn () => auth()->user();

        return $s->components(\App\Filament\Shared\Components\RecordShell::detailTabs('underwriting_case', $overview, null, true, ['documents', 'financial'], [
            self::tab('profile', fn ($r) => Q::profile($r, $viewer())),                      // UND-006
            self::tab('policy_history', fn ($r) => Q::policyHistory($r)),                    // UND-007
            self::tab('claims_history', fn ($r) => Q::claimsHistory($r)),                    // UND-008
            self::tab('questionnaire', fn ($r) => Q::questionnaire($r, $viewer())),          // UND-009
            self::tab('documents', fn ($r) => Q::supportingDocuments($r, $viewer())),        // UND-010
            self::tab('risk_assessment', fn ($r) => Q::riskAssessment($r)),                  // UND-011
            self::tab('risk_score', fn ($r) => Q::riskScore($r)),                            // UND-012
            self::tab('coverage', fn ($r) => Q::coverage($r)),                               // UND-013
            self::tab('pricing', fn ($r) => Q::pricing($r)),                                 // UND-014
            self::tab('information_request', fn ($r) => Q::informationRequest($r, $viewer())), // UND-015
            self::tab('requirements', fn ($r) => Q::requirements($r, $viewer())),            // UND-016
            self::tab('supervisor', fn ($r) => Q::supervisorApproval($r)),                   // UND-019
        ]));
    }

    public static function table(Table $t): Table
    {
        return $t->columns([
            Tables\Columns\TextColumn::make('proposal.proposal_number')->label('Proposal')->searchable(),
            Tables\Columns\TextColumn::make('carrier.cima_code')->label('Carrier'),
            Tables\Columns\TextColumn::make('priority')->badge()->color(fn (?string $state) => $state === 'HIGH' ? 'danger' : 'info'),
            Tables\Columns\TextColumn::make('risk_band')->label(__('uw_workbench.f.risk_band'))->placeholder('—'),
            Tables\Columns\TextColumn::make('referrals_count')->label('Open referrals'),
            \App\Filament\Shared\Columns::date('decision_due_at'),
            \App\Filament\Shared\Columns::status('status'),
        ])->recordActions([Actions\ViewAction::make()])
            ->emptyStateHeading('No underwriting cases')->emptyStateDescription('Submitted proposals requiring carrier review appear here.')->emptyStateIcon('lucide-users-round');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUnderwritingCases::route('/'), 'view' => Pages\ViewUnderwritingCase::route('/{record}')];
    }
}
