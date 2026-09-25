<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ApprovalMatrixRules;

use App\Application\Approvals\ApprovalService;
use App\Application\Approvals\Handlers\ApprovalMatrixChangeHandler;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\ApprovalMatrixRule;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * ESR ADM-029 / REQ-RBAC-006 — central approval matrix. Read-only table; every change is proposed as an
 * approval_matrix.change request and applied only after a different user approves it in the inbox.
 */
final class ApprovalMatrixRuleResource extends Resource
{
    protected static ?string $model = ApprovalMatrixRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|\UnitEnum|null $navigationGroup = 'Approvals';

    protected static ?string $navigationLabel = 'Approval matrix';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'approvals/matrix';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return $u !== null && ($u->hasPermission('approvals.matrix.view')
            || $u->memberships()->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'])->exists());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);

        return parent::getEloquentQuery()->where(fn ($q) => $q->whereNull('tenant_id')->when($tenant, fn ($q) => $q->orWhere('tenant_id', $tenant)));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('action_code')->columns([
            Tables\Columns\TextColumn::make('action_code')->searchable(),
            Tables\Columns\TextColumn::make('category')->badge(),
            Tables\Columns\TextColumn::make('workflow'),
            Tables\Columns\TextColumn::make('tenant_id')->label('Scope')->formatStateUsing(fn ($state) => $state ? 'Tenant' : 'Platform')->placeholder('Platform'),
            Tables\Columns\TextColumn::make('min_amount')->numeric(2)->placeholder('—'),
            Tables\Columns\TextColumn::make('max_amount')->numeric(2)->placeholder('—'),
            Tables\Columns\TextColumn::make('checker_permission')->placeholder('not configured'),
            Tables\Columns\TextColumn::make('required_approvals')->label('Levels'),
            Tables\Columns\IconColumn::make('requires_maker_checker')->boolean()->label('Maker-checker'),
            Tables\Columns\TextColumn::make('source_refs')->label('Sources')->wrap(),
            Tables\Columns\TextColumn::make('status')->badge(),
        ])->filters([
            Tables\Filters\SelectFilter::make('category')->options(array_combine($c = ['FINANCIAL', 'CONFIGURATION', 'ACCESS', 'DOCUMENT', 'UNDERWRITING', 'CLAIM', 'POLICY', 'OVERRIDE', 'DATA'], $c)),
        ])->recordActions([
            Actions\Action::make('propose_change')->label('Propose change')
                ->schema([
                    Forms\Components\TextInput::make('min_amount')->numeric(),
                    Forms\Components\TextInput::make('max_amount')->numeric(),
                    Forms\Components\TextInput::make('checker_permission')->maxLength(128),
                    Forms\Components\TextInput::make('required_approvals')->numeric()->minValue(1)->maxValue(5),
                    Forms\Components\Toggle::make('requires_maker_checker')->default(true),
                    Forms\Components\Textarea::make('reason')->required()->minLength(5),
                ])
                ->fillForm(fn ($record) => $record->only(['min_amount', 'max_amount', 'checker_permission', 'required_approvals', 'requires_maker_checker']))
                ->action(function ($record, array $data) {
                    $reason = $data['reason'];
                    unset($data['reason']);
                    if (ServiceValidation::run(fn () => ApprovalMatrixChangeHandler::propose(app(ApprovalService::class), auth()->user(), $record->id, $data, $reason))) {
                        Notification::make()->success()->title('Change submitted for approval')->send();
                    }
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListApprovalMatrixRules::route('/')];
    }
}
