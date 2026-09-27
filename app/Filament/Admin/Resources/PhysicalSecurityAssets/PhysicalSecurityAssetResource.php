<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PhysicalSecurityAssets;

use App\Application\Documents\Security\EnforcementReadiness;
use App\Application\Documents\Security\PhysicalSecurityRegistry;
use Illuminate\Support\HtmlString;
use App\Filament\Admin\Concerns\DocumentEngineAccess;
use App\Models\DocumentSecurity\PhysicalSecurityAsset;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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

    protected static string|BackedEnum|null $navigationIcon = 'lucide-fingerprint';

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
                Forms\Components\Select::make('asset_kind')->options(self::KIND_LABELS)->required()->live(),
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
            Section::make('Enforcement status (read-only)')->collapsible()->schema([
                Forms\Components\Placeholder::make('enforcement_status')->hiddenLabel()->content(fn () => self::enforcementHtml()),
            ]),
            Section::make('Seal artwork')->description('SEAL-01 corporate seal: upload the official artwork (PNG or SVG). It counts only once VERIFIED by a second administrator.')
                ->visible(fn ($get) => in_array($get('asset_kind'), ['SEAL_ARTWORK', null], true))->columns(2)->schema([
                    Forms\Components\FileUpload::make('artwork_path')->label('Artwork file')->disk('local')->directory('document-security/seal-artwork')->visibility('private')
                        ->acceptedFileTypes(['image/png', 'image/svg+xml'])->maxSize(2048),
                    Forms\Components\Placeholder::make('artwork_preview')->label('Stored artwork')->content(fn (?PhysicalSecurityAsset $record) => self::artworkPreview($record)),
                ]),
            Section::make('Security features')->description('What the supplier certifies for this batch / capability. Recorded as stated; nothing is simulated on the PDF.')->columns(3)->schema([
                Forms\Components\TextInput::make('security_features.paper_stock_grade')->label('Paper stock grade / type')->maxLength(120),
                Forms\Components\TextInput::make('security_features.paper_weight_gsm')->label('Paper weight (g/m²)')->numeric()->minValue(40)->maxValue(400),
                Forms\Components\TextInput::make('security_features.watermark_paper')->label('Paper watermark')->maxLength(120),
                Forms\Components\TextInput::make('security_features.uv_ink')->label('UV ink / fibres')->maxLength(120),
                Forms\Components\TextInput::make('security_features.hologram_type')->label('Hologram type')->maxLength(120),
                Forms\Components\TextInput::make('security_features.hologram_serial_format')->label('Hologram serial format')->maxLength(64),
                Forms\Components\TextInput::make('security_features.certificate_reference')->label('Supplier certificate / test report')->maxLength(120)->columnSpan(2),
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
            \App\Filament\Shared\Columns::status('status'),
            \App\Filament\Shared\Columns::date('verified_at'),
        ])->recordActions([Actions\EditAction::make()]);
    }

    /** Inline preview of the stored seal artwork (private disk → data URI, hash-checked). */
    public static function artworkPreview(?PhysicalSecurityAsset $record): HtmlString
    {
        $path = $record?->artwork_path;
        $disk = Storage::disk('local');
        if (! $path || ! $disk->exists($path)) {
            return new HtmlString('<span class="text-sm text-gray-500">No artwork stored.</span>');
        }
        $bytes = (string) $disk->get($path);
        if ($record->artwork_sha256 && ! hash_equals($record->artwork_sha256, hash('sha256', $bytes))) {
            return new HtmlString('<span class="text-sm text-danger-600">Stored file does not match its recorded hash.</span>');
        }
        $mime = str_contains(substr($bytes, 0, 512), '<svg') ? 'image/svg+xml' : 'image/png';

        return new HtmlString('<img src="data:'.$mime.';base64,'.base64_encode($bytes).'" alt="Seal artwork" style="max-height:140px;max-width:220px;border:1px solid #e5e7eb;border-radius:6px;padding:6px;background:#fff">'
            .'<div class="text-xs text-gray-500 mt-1 font-mono">sha256 '.e(substr((string) $record->artwork_sha256, 0, 16)).'…</div>');
    }

    /** DOCUMENT_ENFORCE_CONTROLS status and the config-dependent gate steps that would refuse (read-only). */
    public static function enforcementHtml(): HtmlString
    {
        $s = EnforcementReadiness::summary();
        $html = '<div class="text-sm"><p><strong>DOCUMENT_ENFORCE_CONTROLS: '.($s['enforced'] ? '<span style="color:#15803d">ON</span>' : '<span style="color:#b45309">OFF</span>').'</strong> — '
            .($s['enforced'] ? 'documents whose gate steps are CONFIG_REQUIRED are refused.' : 'CONFIG_REQUIRED steps are recorded on each document but do not block issuance.').'</p>';
        foreach ($s['types'] as $t) {
            $html .= '<p class="mt-2 font-semibold">'.e($t['code']).' ('.e((string) $t['tier']).')</p><ul class="list-disc ms-5">';
            foreach ($t['steps'] as $st) {
                $ok = in_array($st['status'], ['PASS', 'NOT_APPLICABLE'], true);
                $html .= '<li><span class="font-mono">GATE-'.sprintf('%02d', $st['step']).'</span> '.e($st['check']).': <strong style="color:'.($ok ? '#15803d' : '#b91c1c').'">'.e($st['status']).'</strong>'
                    .($st['reason'] ? ' <span class="text-gray-500">('.e($st['reason']).')</span>' : '').'</li>';
            }
            $html .= '</ul><p class="text-gray-600">'.($t['would_refuse'] === [] ? 'Would issue with enforcement on.' : 'Would be refused with enforcement on: '.e(implode('; ', $t['would_refuse']))).'</p>';
        }

        return new HtmlString($html.'</div>');
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
            'edit' => Pages\EditPhysicalSecurityAsset::route('/{record}/edit'), 'view' => Pages\ViewPhysicalSecurityAsset::route('/{record}')];
    }
}
