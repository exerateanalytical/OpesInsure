<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentIssuanceProfiles;

use App\Application\Documents\Engine\DocumentAccessPolicy;
use App\Application\Documents\Engine\DocumentRegister;
use App\Filament\Admin\Concerns\DocumentEngineAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** DOC-ADM-015 issuance rules: OpesInsure renders an insurer document only when that insurer configured OPES_GENERATED/HYBRID and authorized rendering. */
final class DocumentIssuanceProfileResource extends Resource
{
    use DocumentEngineAccess;

    protected static ?string $model = \App\Models\DocumentIssuanceProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Issuance rules';

    protected static ?int $navigationSort = 308;

    protected static ?string $slug = 'document-engine/issuance-rules';

    public static function canCreate(): bool
    {
        return true && static::canAccessDocumentEngine();
    }

    public static function canEdit($record): bool
    {
        return true && static::canAccessDocumentEngine();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Issuer authority')->columns(3)->schema([
                Forms\Components\Select::make('carrier_id')->label('Insurer')->options(fn () => \App\Models\Carrier::with('party')->get()->mapWithKeys(fn ($c) => [$c->id => $c->party?->display_name ?? $c->cima_code])->all())->searchable()->required()->disabledOn('edit'),
                Forms\Components\Select::make('product_id')->label('Product (empty = all)')->options(fn () => \App\Models\InsuranceProduct::orderBy('code')->get()->mapWithKeys(fn ($p) => [$p->id => $p->code.' v'.$p->version.' · '.$p->name])->all())->searchable()->disabledOn('edit'),
                Forms\Components\Select::make('issuance_mode')->options(['MANUAL_UPLOAD' => 'Carrier/broker upload (Mode 1)', 'OPES_GENERATED' => 'OpesInsure generated', 'INSURER_API' => 'Insurer API', 'HYBRID' => 'Hybrid'])->required()->default('MANUAL_UPLOAD'),
                Forms\Components\Toggle::make('opes_rendering_authorized')->label('Insurer authorizes OpesInsure to render its documents'),
                Forms\Components\TextInput::make('authorization_reference')->maxLength(120)->helperText('Mandate / agreement reference'),
                Forms\Components\Select::make('default_language')->options(['BILINGUAL' => 'Bilingual', 'FR' => 'Français', 'EN' => 'English'])->default('BILINGUAL'),
            ]),
            Section::make('Signature (DOC-ADM-013)')->columns(3)->schema([
                Forms\Components\Select::make('signature_mode')->options(['NONE' => 'None', 'IMAGE' => 'Printed signatory', 'DIGITAL' => 'Digital (PENDING_SIGNATURE)'])->default('NONE'),
                Forms\Components\TextInput::make('signatory_name')->maxLength(160),
                Forms\Components\TextInput::make('signatory_title')->maxLength(160),
            ]),
            Section::make('QR (DOC-ADM-014)')->columns(2)->schema([
                Forms\Components\Toggle::make('qr_enabled')->default(true),
                Forms\Components\Select::make('qr_payload')->options(['VERIFY_URL' => 'Public verification URL'])->default('VERIFY_URL'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('carrier.party.display_name')->label('Insurer')->searchable(),
            Tables\Columns\TextColumn::make('product.code')->label('Product')->placeholder('All products'),
            Tables\Columns\TextColumn::make('issuance_mode')->badge(),
            Tables\Columns\IconColumn::make('opes_rendering_authorized')->label('Authorized')->boolean(),
            Tables\Columns\TextColumn::make('signature_mode'),
            Tables\Columns\IconColumn::make('qr_enabled')->label('QR')->boolean(),
        ])->recordActions([Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentIssuanceProfiles::route('/'),
            'create' => Pages\CreateDocumentIssuanceProfile::route('/create'),
            'edit' => Pages\EditDocumentIssuanceProfile::route('/{record}/edit'),
        ];
    }
}
