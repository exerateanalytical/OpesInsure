<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaInsurerAuthorizations;

use App\Application\Regulatory\CimaAuthorizationService;
use App\Filament\Admin\Concerns\CimaRegulatoryAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\CimaProductMappings\CimaProductMappingResource;
use App\Models\Carrier;
use App\Models\Regulatory\InsurerRegulatoryAuthorization;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * PLT-CIMA-009 insurer → CIMA authorization (agrément) → authorized branches.
 * Recorded from regulator evidence (source + reference mandatory), approved by
 * a second admin. Only ACTIVE authorizations unlock product publication.
 */
final class CimaInsurerAuthorizationResource extends Resource
{
    use CimaRegulatoryAccess;

    protected static ?string $model = InsurerRegulatoryAuthorization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $navigationLabel = 'Insurer branch authorization';

    protected static ?string $modelLabel = 'insurer CIMA authorization';

    protected static ?int $navigationSort = 209;

    public static function createAction(?string $carrierId = null): Actions\Action
    {
        return Actions\Action::make('record')->label('Record authorization')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => static::canAccessCima())
            ->schema([
                Forms\Components\Select::make('carrier_id')->label('Insurer')->required()->searchable()->live()->default($carrierId)
                    ->options(fn () => Carrier::orderBy('legal_name')->get()->mapWithKeys(fn ($c) => [$c->id => ($c->legal_name ?: $c->cima_code).($c->is_demo ? ' (DEMO)' : '')])->all()),
                // REQ-DUP-017: the official register (IARD/LIFE per year) feeds the record; branches still come from evidence.
                Forms\Components\Select::make('register_authorization_id')->label('Official register entry (licence year)')
                    ->helperText('Auto-selected from the latest AUTHORIZED register year when left empty. The register never grants branches by itself.')
                    ->options(fn ($get) => $get('carrier_id') ? app(CimaAuthorizationService::class)->registerSource($get('carrier_id'))
                        ->mapWithKeys(fn ($r) => [$r->id => "{$r->reference_year} · {$r->branch} · {$r->status} ({$r->source_authority})"])->all() : []),
                Forms\Components\TextInput::make('authorization_reference')->label('Authorization reference (arrêté / agrément no.)')->required()->maxLength(160),
                Forms\Components\Select::make('source')->required()->options([
                    'REGULATOR_DECREE' => 'Ministerial decree (arrêté)', 'REGULATOR_LETTER' => 'Regulator letter', 'OFFICIAL_GAZETTE' => 'Official gazette',
                    'CIMA_CRCA_DECISION' => 'CRCA decision', 'DEMO' => 'DEMO (demo carriers only)',
                ]),
                Forms\Components\TextInput::make('source_document')->label('Regulator evidence (document URL / archive ref)')->maxLength(500)
                    ->requiredUnless('source', 'DEMO'),
                Forms\Components\DatePicker::make('effective_from')->required(),
                Forms\Components\DatePicker::make('effective_until'),
                Forms\Components\Select::make('branches')->label('Authorized CIMA branches')->multiple()->required()->options(fn () => CimaProductMappingResource::branchOptions()),
                Forms\Components\Textarea::make('notes'),
            ])
            ->action(function (array $data) {
                $carrier = Carrier::findOrFail($data['carrier_id']);
                if (ServiceValidation::run(fn () => app(CimaAuthorizationService::class)->record($carrier, $data, $data['branches'] ?? [], auth()->user()))) {
                    Notification::make()->title('Authorization recorded — awaiting approval by another admin')->success()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->modifyQueryUsing(fn ($query) => $query->with(['carrier', 'branches', 'registerAuthorization']))->columns([
            Tables\Columns\TextColumn::make('carrier.legal_name')->label('Insurer')->searchable()->placeholder('-'),
            Tables\Columns\TextColumn::make('authorization_reference')->label('Reference')->searchable(),
            Tables\Columns\TextColumn::make('registerAuthorization.reference_year')->label('Register year')->placeholder('Not on register'),
            Tables\Columns\TextColumn::make('source')->badge()->color(fn (string $state) => $state === 'DEMO' ? 'warning' : 'gray'),
            Tables\Columns\TextColumn::make('branches_list')->label('Branches')->state(fn (InsurerRegulatoryAuthorization $a) => $a->branches->where('status', 'ACTIVE')->map(fn ($b) => (int) substr($b->branch_code, 5, 2))->sort()->join(', '))->wrap(),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) { 'ACTIVE' => 'success', 'PENDING_APPROVAL' => 'warning', 'SUSPENDED' => 'danger', default => 'gray' }),
            Tables\Columns\TextColumn::make('effective_from')->date(),
            Tables\Columns\TextColumn::make('effective_until')->date()->placeholder('Open'),
        ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['PENDING_APPROVAL' => 'Pending approval', 'ACTIVE' => 'Active', 'SUSPENDED' => 'Suspended', 'REVOKED' => 'Revoked', 'REJECTED' => 'Rejected']),
                Tables\Filters\TernaryFilter::make('is_demo')->label('Demo'),
            ])
            ->recordActions([
                Actions\Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                    ->visible(fn (InsurerRegulatoryAuthorization $a) => $a->status === 'PENDING_APPROVAL')
                    ->action(function (InsurerRegulatoryAuthorization $a) {
                        if (ServiceValidation::run(fn () => app(CimaAuthorizationService::class)->approve($a, auth()->user()))) {
                            Notification::make()->title('Authorization approved')->success()->send();
                        }
                    }),
                ...collect(['REJECTED' => 'Reject', 'SUSPENDED' => 'Suspend', 'REVOKED' => 'Revoke', 'ACTIVE' => 'Reinstate'])->map(fn ($label, $status) => Actions\Action::make('status'.$status)->label($label)->color($status === 'ACTIVE' ? 'success' : 'danger')
                    ->visible(fn (InsurerRegulatoryAuthorization $a) => match ($status) { 'REJECTED' => $a->status === 'PENDING_APPROVAL', 'SUSPENDED' => $a->status === 'ACTIVE', 'REVOKED' => in_array($a->status, ['ACTIVE', 'SUSPENDED'], true), 'ACTIVE' => $a->status === 'SUSPENDED' })
                    ->schema([Forms\Components\Textarea::make('reason')->required()->minLength(5)])
                    ->action(fn (InsurerRegulatoryAuthorization $a, array $data) => ServiceValidation::run(fn () => app(CimaAuthorizationService::class)->changeStatus($a, $status, auth()->user(), $data['reason']))))->values()->all(),
            ])
            ->emptyStateHeading('No insurer authorizations recorded')
            ->emptyStateDescription('Record each insurer\'s CIMA agrément and authorized branches from regulator evidence to unlock product publication.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCimaInsurerAuthorizations::route('/')];
    }
}
