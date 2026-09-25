<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ApprovalRequests;

use App\Application\Approvals\ApprovalService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\ApprovalRequest;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** WF-081 / REQ-RBAC-005 — the one approval inbox. Decisions go through ApprovalService (maker-checker, SoD, matrix). */
final class ApprovalRequestResource extends Resource
{
    protected static ?string $model = ApprovalRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|\UnitEnum|null $navigationGroup = 'Approvals';

    protected static ?string $navigationLabel = 'Approval inbox';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'approvals/inbox';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return $u !== null && ($u->hasPermission('approvals.inbox.view')
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

    public static function getNavigationBadge(): ?string
    {
        $n = rescue(fn () => static::getEloquentQuery()->where('status', 'PENDING')->count(), 0, false);

        return $n ? (string) $n : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        $svc = fn (): ApprovalService => app(ApprovalService::class);

        return $table->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('action_code')->label('Action')->badge()->searchable(),
            Tables\Columns\TextColumn::make('subject_type')->label('Subject'),
            Tables\Columns\TextColumn::make('amount')->numeric(2)->placeholder('—'),
            Tables\Columns\TextColumn::make('reason')->wrap()->limit(80),
            Tables\Columns\TextColumn::make('requester.full_name')->label('Requested by'),
            Tables\Columns\TextColumn::make('approvals_count')->label('Level')->formatStateUsing(fn ($state, $record) => $state.' / '.$record->required_approvals),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) { 'PENDING' => 'warning', 'APPROVED', 'AUTO_APPROVED' => 'success', 'REJECTED' => 'danger', default => 'gray' }),
            Tables\Columns\TextColumn::make('created_at')->dateTime(),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(['PENDING' => 'Pending', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected', 'CANCELLED' => 'Cancelled', 'AUTO_APPROVED' => 'Auto-approved'])->default('PENDING'),
            Tables\Filters\SelectFilter::make('action_code')->options(fn () => collect(\App\Application\Approvals\ApprovalActionCatalogue::ACTIONS)->mapWithKeys(fn ($a, $k) => [$k => $k])->all()),
        ])->recordActions([
            Actions\Action::make('approve')->color('success')->requiresConfirmation()
                ->visible(fn ($record) => $record->status === 'PENDING' && $svc()->canDecide($record, auth()->user()))
                ->schema([Forms\Components\Textarea::make('note')])
                ->action(fn ($record, array $data) => ServiceValidation::run(fn () => $svc()->approve($record, auth()->user(), $data['note'] ?? null))),
            Actions\Action::make('reject')->color('danger')
                ->visible(fn ($record) => $record->status === 'PENDING' && $svc()->canDecide($record, auth()->user()))
                ->schema([Forms\Components\Textarea::make('note')->required()])
                ->action(fn ($record, array $data) => ServiceValidation::run(fn () => $svc()->reject($record, auth()->user(), $data['note']))),
            Actions\Action::make('withdraw')->color('gray')->requiresConfirmation()
                ->visible(fn ($record) => $record->status === 'PENDING' && $record->requested_by === auth()->id())
                ->schema([Forms\Components\Textarea::make('reason')->required()])
                ->action(fn ($record, array $data) => ServiceValidation::run(fn () => $svc()->cancel($record, auth()->user(), $data['reason']))),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListApprovalRequests::route('/')];
    }
}
