<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\KycSubmissions;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Columns;
use App\Models\KycSubmission;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** REQ-KYC-001..003 staff KYC review queue (GET /api/v1/kyc/submissions, kyc.view); review actions on the detail page (KycActions). */
final class KycSubmissionResource extends \App\Filament\Shared\LocalizedResource
{
    protected static ?string $model = KycSubmission::class;

    protected static ?string $modelLabel = 'KYC submission';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-id-card';

    protected static string|\UnitEnum|null $navigationGroup = 'Trust & compliance';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'KYC reviews';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasPermission('kyc.view');
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny() && $record->getAttribute('tenant_id') === app(TenantContext::class)->id();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', app(TenantContext::class)->id())->with('party');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('party.display_name')->label(__('kyc_actions.fields.party'))->searchable(),
            Columns::text('subject_kind', __('kyc_actions.fields.subject_kind')),
            Columns::text('kyc_level', __('kyc_actions.fields.kyc_level')),
            Columns::status('status', __('kyc_actions.fields.status')),
            Columns::status('screening_status', __('kyc_actions.fields.screening_status')),
            Columns::date('submitted_at', true, __('kyc_actions.fields.submitted_at')),
            Columns::date('expires_at', false, __('kyc_actions.fields.expires_at')),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->label(__('kyc_actions.fields.status'))->options(collect(['DRAFT', 'SUBMITTED', 'REVIEWING', 'MORE_INFO_REQUIRED', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'EXPIRED'])
                ->mapWithKeys(fn ($s) => [$s => Columns::humanise($s)])->all()),
        ])->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListKycSubmissions::route('/'), 'view' => Pages\ViewKycSubmission::route('/{record}')];
    }
}
