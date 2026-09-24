<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaProductMappings;

use App\Application\Regulatory\CimaProductMappingService;
use App\Filament\Admin\Concerns\CimaRegulatoryAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\InsuranceProduct;
use App\Models\Regulatory\ProductRegulatoryMapping;
use App\Models\Regulatory\RegulatoryBranch;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * PLT-CIMA-008 product → CIMA branch mapping. Class defaults are applied
 * automatically; overrides are proposed here (maker) and approved by a
 * different admin (checker). Mappings are never deleted, only retired.
 */
final class CimaProductMappingResource extends Resource
{
    use CimaRegulatoryAccess;

    protected static ?string $model = ProductRegulatoryMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'Product mapping';

    protected static ?string $modelLabel = 'product CIMA mapping';

    protected static ?int $navigationSort = 208;

    public static function branchOptions(bool $includeReserved = false): array
    {
        return RegulatoryBranch::current()->when(! $includeReserved, fn ($q) => $q->where('reserved', false))->orderBy('number')->get()
            ->mapWithKeys(fn ($b) => [$b->code => "{$b->number} — {$b->label_fr}"])->all();
    }

    public static function createAction(): Actions\Action
    {
        return Actions\Action::make('propose')->label('Propose mapping')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => static::canAccessCima())
            ->schema([
                Forms\Components\Select::make('insurance_product_id')->label('Product (version)')->required()->searchable()
                    ->options(fn () => InsuranceProduct::with('carrier')->orderBy('code')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->code} v{$p->version} · {$p->name} · {$p->status}"])->all()),
                Forms\Components\Select::make('branch_code')->label('CIMA branch')->options(fn () => static::branchOptions())->required()->searchable(),
                Forms\Components\Select::make('relationship_type')->options(['PRIMARY' => 'Primary', 'ACCESSORY' => 'Accessory', 'COMPLEMENTARY' => 'Complementary (life 20/21)'])->required()
                    ->helperText('Branches 14 (Crédit) and 15 (Caution) can never be accessory (Article 328-1).'),
                Forms\Components\DatePicker::make('effective_from')->required()->default(now()),
                Forms\Components\DatePicker::make('effective_until'),
                Forms\Components\TextInput::make('legal_reference')->placeholder('Article 328'),
                Forms\Components\Textarea::make('notes'),
            ])
            ->action(function (array $data) {
                $product = InsuranceProduct::findOrFail($data['insurance_product_id']);
                if (ServiceValidation::run(fn () => app(CimaProductMappingService::class)->propose($product, $data, auth()->user()))) {
                    Notification::make()->title('Mapping proposed — awaiting approval by another admin')->success()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->modifyQueryUsing(fn ($query) => $query->with('product.carrier'))->columns([
            Tables\Columns\TextColumn::make('product.code')->label('Product')->searchable()->description(fn (ProductRegulatoryMapping $m) => $m->product?->name),
            Tables\Columns\TextColumn::make('product_version')->label('Ver.'),
            Tables\Columns\TextColumn::make('product.carrier.legal_name')->label('Insurer')->placeholder('-')->toggleable(),
            Tables\Columns\TextColumn::make('branch_code')->label('CIMA branch')->formatStateUsing(fn (string $state) => static::branchOptions(true)[$state] ?? $state)->wrap(),
            Tables\Columns\TextColumn::make('relationship_type')->label('Type')->badge()->color(fn (string $state) => match ($state) { 'PRIMARY' => 'primary', 'COMPLEMENTARY' => 'warning', default => 'gray' }),
            Tables\Columns\TextColumn::make('source')->badge()->color(fn (string $state) => $state === 'ADMIN' ? 'success' : 'gray'),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) { 'ACTIVE' => 'success', 'PENDING_APPROVAL' => 'warning', default => 'gray' }),
            Tables\Columns\TextColumn::make('effective_from')->date(),
            Tables\Columns\TextColumn::make('effective_until')->date()->placeholder('Open'),
        ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['ACTIVE' => 'Active', 'PENDING_APPROVAL' => 'Pending approval', 'SUPERSEDED' => 'Superseded', 'REJECTED' => 'Rejected']),
                Tables\Filters\SelectFilter::make('relationship_type')->options(['PRIMARY' => 'Primary', 'ACCESSORY' => 'Accessory', 'COMPLEMENTARY' => 'Complementary']),
                Tables\Filters\SelectFilter::make('source')->options(['CLASS_DEFAULT' => 'Class default (automatic)', 'ADMIN' => 'Admin override']),
            ])
            ->recordActions([
                Actions\Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                    ->visible(fn (ProductRegulatoryMapping $m) => $m->status === 'PENDING_APPROVAL')
                    ->action(function (ProductRegulatoryMapping $m) {
                        if (ServiceValidation::run(fn () => app(CimaProductMappingService::class)->approve($m, auth()->user()))) {
                            Notification::make()->title('Mapping approved')->success()->send();
                        }
                    }),
                Actions\Action::make('reject')->label('Reject')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (ProductRegulatoryMapping $m) => $m->status === 'PENDING_APPROVAL')
                    ->schema([Forms\Components\Textarea::make('reason')->required()->minLength(5)])
                    ->action(fn (ProductRegulatoryMapping $m, array $data) => ServiceValidation::run(fn () => app(CimaProductMappingService::class)->reject($m, auth()->user(), $data['reason']))),
                Actions\Action::make('retire')->label('Retire')->icon(Heroicon::OutlinedArchiveBox)->color('gray')
                    ->visible(fn (ProductRegulatoryMapping $m) => $m->status === 'ACTIVE')
                    ->schema([Forms\Components\Textarea::make('reason')->required()->minLength(5)])
                    ->action(fn (ProductRegulatoryMapping $m, array $data) => ServiceValidation::run(fn () => app(CimaProductMappingService::class)->retire($m, auth()->user(), $data['reason']))),
            ])
            ->emptyStateHeading('No product mappings yet')
            ->emptyStateDescription('Run php artisan opesinsure:seed-cima to apply the class defaults.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCimaProductMappings::route('/')];
    }
}
