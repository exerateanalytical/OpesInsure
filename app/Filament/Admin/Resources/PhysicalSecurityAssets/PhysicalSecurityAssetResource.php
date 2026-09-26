<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PhysicalSecurityAssets;

use App\Application\Documents\Security\PhysicalSecurityRegistry;
use App\Filament\Admin\Concerns\DocumentEngineAccess;
use App\Models\DocumentSecurity\PhysicalSecurityAsset;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Security Matrix §4 / §6 physical controls (work item D6): record the secure-print supplier, UV capability,
 * hologram and secure-stock serial batches, and the corporate seal artwork. Nothing counts until an asset is
 * VERIFIED by a different administrator than the one who recorded it; until then the controls stay CONFIG_REQUIRED.
 * Rows are never deleted: retire them.
 */
final class PhysicalSecurityAssetResource extends Resource
{
    use DocumentEngineAccess;

    protected static ?string $model = PhysicalSecurityAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?string $navigationLabel = 'Physical security assets';

    protected static ?int $navigationSort = 309;

    protected static ?string $slug = 'document-engine/physical-security-assets';

    public const KIND_LABELS = [
        'PRINT_SUPPLIER' => 'Secure print supplier (PS-01)', 'SECURE_STOCK_BATCH' => 'Secure stock serial batch (PS-04)',
        'HOLOGRAM_BATCH' => 'Hologram serial batch (PS-03)', 'UV_CAPABILITY' => 'UV print capability (PS-02)', 'SEAL_ARTWORK' => 'Seal artwork (SEAL-01 …)',
    ];

    public static function canCreate(): bool
    {
        return static::canAccessDocumentEngine();
    }

    public static function canEdit($record): bool
    {
        return static::canAccessDocumentEngine();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Asset')->columns(3)->schema([
                Forms\Components\Select::make('asset_kind')->options(self::KIND_LABELS)->required(),
                Forms\Components\Select::make('physical_profile_code')->label('Physical profile')->options(['PS-01' => 'PS-01 Standard secure print', 'PS-02' => 'PS-02 UV', 'PS-03' => 'PS-03 Holographic', 'PS-04' => 'PS-04 Controlled stock']),
                Forms\Components\Select::make('seal_profile_code')->label('Seal profile')->options(['SEAL-01' => 'SEAL-01 Corporate', 'SEAL-03' => 'SEAL-03 Finance', 'SEAL-04' => 'SEAL-04 Claims', 'SEAL-05' => 'SEAL-05 Provider', 'SEAL-06' => 'SEAL-06 Broker verified']),
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\Select::make('carrier_id')->label('Insurer (empty = platform)')->options(fn () => \App\Models\Carrier::with('party')->get()->mapWithKeys(fn ($c) => [$c->id => $c->party?->display_name ?? $c->cima_code])->all())->searchable(),
                Forms\Components\TextInput::make('branch_code')->label('Branch allocation')->maxLength(64),
            ]),
            Section::make('Supplier')->columns(2)->schema([
                Forms\Components\TextInput::make('supplier_name')->maxLength(255),
                Forms\Components\TextInput::make('supplier_reference')->label('Contract / PO reference')->maxLength(120),
            ]),
            Section::make('Serials and custody')->columns(4)->schema([
                Forms\Components\TextInput::make('batch_reference')->maxLength(120),
                Forms\Components\TextInput::make('serial_prefix')->maxLength(32),
                Forms\Components\TextInput::make('serial_from')->numeric()->minValue(0),
                Forms\Components\TextInput::make('serial_to')->numeric()->minValue(0),
                Forms\Components\TextInput::make('quantity_received')->numeric()->minValue(0)->default(0),
                Forms\Components\TextInput::make('quantity_issued')->numeric()->minValue(0)->default(0),
                Forms\Components\TextInput::make('quantity_spoiled')->numeric()->minValue(0)->default(0),
                Forms\Components\TextInput::make('quantity_destroyed')->numeric()->minValue(0)->default(0),
                Forms\Components\TextInput::make('custodian_name')->maxLength(255)->columnSpan(2),
            ]),
            Section::make('Seal artwork')->schema([
                Forms\Components\FileUpload::make('artwork_path')->disk('local')->directory('document-security/seal-artwork')->acceptedFileTypes(['image/png', 'image/svg+xml'])->maxSize(2048),
            ]),
            Section::make('Status')->columns(2)->schema([
                Forms\Components\Select::make('status')->options(['PENDING_VERIFICATION' => 'Pending verification', 'CONFIG_REQUIRED' => 'Config required', 'VERIFIED' => 'Verified', 'RETIRED' => 'Retired'])->default('PENDING_VERIFICATION')->required(),
                Forms\Components\Textarea::make('notes')->rows(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('asset_kind')->badge()->sortable(),
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('physical_profile_code')->label('PS'),
            Tables\Columns\TextColumn::make('seal_profile_code')->label('Seal'),
            Tables\Columns\TextColumn::make('supplier_name')->searchable(),
            Tables\Columns\TextColumn::make('batch_reference'),
            Tables\Columns\TextColumn::make('serials')->state(fn (PhysicalSecurityAsset $r) => $r->serial_from !== null ? $r->serial_prefix.$r->serial_from.' – '.$r->serial_prefix.$r->serial_to : null),
            Tables\Columns\TextColumn::make('balance')->state(fn (PhysicalSecurityAsset $r) => $r->balance()),
            Tables\Columns\TextColumn::make('status')->badge(),
            Tables\Columns\TextColumn::make('verified_at')->dateTime(),
        ])->recordActions([Actions\EditAction::make()]);
    }

    /**
     * Shared by the create / edit pages: serial range and custody checks, artwork hash, maker-checker verification.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepare(array $data, ?PhysicalSecurityAsset $record = null, ?string $actorId = null): array
    {
        $actorId ??= auth()->id();
        if (isset($data['serial_from'], $data['serial_to']) && $data['serial_from'] !== '' && $data['serial_to'] !== '' && (int) $data['serial_to'] < (int) $data['serial_from']) {
            throw ValidationException::withMessages(['data.serial_to' => 'The serial range end is before its start.']);
        }
        $out = (int) ($data['quantity_issued'] ?? 0) + (int) ($data['quantity_spoiled'] ?? 0) + (int) ($data['quantity_destroyed'] ?? 0);
        if ($out > (int) ($data['quantity_received'] ?? 0)) {
            throw ValidationException::withMessages(['data.quantity_issued' => 'Issued + spoiled + destroyed cannot exceed the quantity received.']);
        }
        if (! empty($data['artwork_path'])) {
            $disk = Storage::disk('local');
            $data['artwork_sha256'] = $disk->exists($data['artwork_path']) ? hash('sha256', (string) $disk->get($data['artwork_path'])) : null;
        }
        if (($data['status'] ?? null) === 'VERIFIED') {
            if ($record?->status !== 'VERIFIED') {
                if ($record === null || $record->recorded_by === $actorId) {
                    throw ValidationException::withMessages(['data.status' => 'A physical security asset is verified by a different administrator after it is recorded.']);
                }
                $data['verified_by'] = $actorId;
                $data['verified_at'] = now();
            }
        } else {
            $data['verified_by'] = null;
            $data['verified_at'] = null;
        }

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPhysicalSecurityAssets::route('/'),
            'create' => Pages\CreatePhysicalSecurityAsset::route('/create'),
            'edit' => Pages\EditPhysicalSecurityAsset::route('/{record}/edit'),
        ];
    }
}
