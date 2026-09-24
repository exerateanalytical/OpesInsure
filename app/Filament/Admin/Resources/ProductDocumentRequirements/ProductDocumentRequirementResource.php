<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductDocumentRequirements;

use App\Application\DocumentCatalogue\ProductDocumentRequirementService;
use App\Filament\Admin\Concerns\DocumentCatalogueAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\DocumentCatalogue\DocumentProductType;
use App\Models\DocumentCatalogue\DocumentType;
use App\Models\DocumentCatalogue\ProductDocumentRequirement;
use App\Models\InsuranceProduct;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Product-version document requirements: selection of the matrix product type
 * and insurer level overrides. Maker proposes, a different admin approves;
 * rows are retired, never deleted. Platform matrix and packs are unchanged.
 */
final class ProductDocumentRequirementResource extends Resource
{
    use DocumentCatalogueAccess;

    protected static ?string $model = ProductDocumentRequirement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Product overrides';

    protected static ?string $modelLabel = 'product document requirement';

    protected static ?int $navigationSort = 305;

    protected static ?string $slug = 'product-document-requirements';

    public static function createAction(): Actions\Action
    {
        return Actions\Action::make('propose')->label('Propose')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => static::canAccessDocumentCatalogue())
            ->schema([
                Forms\Components\Select::make('insurance_product_id')->label('Product (version)')->required()->searchable()
                    ->options(fn () => InsuranceProduct::orderBy('code')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->code} v{$p->version} · {$p->name} · {$p->status}"])->all()),
                Forms\Components\Select::make('kind')->required()->live()->options(['PRODUCT_TYPE' => 'Select document product type', 'MATRIX_OVERRIDE' => 'Override a matrix level']),
                Forms\Components\Select::make('product_type_code')->label('Document product type')->searchable()
                    ->options(fn () => DocumentProductType::orderBy('spec_section')->get()->mapWithKeys(fn ($t) => [$t->code => "{$t->code} — {$t->label_fr}"])->all())
                    ->visible(fn (Get $get) => $get('kind') === 'PRODUCT_TYPE')->required(fn (Get $get) => $get('kind') === 'PRODUCT_TYPE'),
                Forms\Components\Select::make('stage')->options(array_combine(ProductDocumentRequirementService::stages(), ProductDocumentRequirementService::stages()))
                    ->visible(fn (Get $get) => $get('kind') === 'MATRIX_OVERRIDE')->required(fn (Get $get) => $get('kind') === 'MATRIX_OVERRIDE'),
                Forms\Components\Select::make('document_type_id')->label('Document')->searchable()
                    ->getSearchResultsUsing(fn (string $search) => DocumentType::where('type_id', 'ilike', "%$search%")->orWhere('canonical_code', 'ilike', "%$search%")->orWhere('name_fr', 'ilike', "%$search%")
                        ->limit(50)->get()->mapWithKeys(fn ($t) => [$t->type_id => "{$t->type_id} — {$t->name_fr}"])->all())
                    ->getOptionLabelUsing(fn ($value) => DocumentType::where('type_id', $value)->value('name_fr'))
                    ->visible(fn (Get $get) => $get('kind') === 'MATRIX_OVERRIDE')->required(fn (Get $get) => $get('kind') === 'MATRIX_OVERRIDE'),
                Forms\Components\TextInput::make('variant_code')->label('Variant (optional)')->visible(fn (Get $get) => $get('kind') === 'MATRIX_OVERRIDE'),
                Forms\Components\Select::make('level')->options(['M' => 'M — mandatory', 'C' => 'C — conditional', 'O' => 'O — optional', 'I' => 'I — internal', 'T' => 'T — third-party supplied'])
                    ->visible(fn (Get $get) => $get('kind') === 'MATRIX_OVERRIDE')->required(fn (Get $get) => $get('kind') === 'MATRIX_OVERRIDE')
                    ->helperText('Mandatory documents that are not insurer-overridable cannot be downgraded.'),
                Forms\Components\Textarea::make('condition_note'),
                Forms\Components\Textarea::make('reason')->required()->minLength(5),
            ])
            ->action(function (array $data) {
                $product = InsuranceProduct::findOrFail($data['insurance_product_id']);
                if (ServiceValidation::run(fn () => app(ProductDocumentRequirementService::class)->propose($product, $data, auth()->user()))) {
                    Notification::make()->title('Proposed — awaiting approval by another admin')->success()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        $svc = fn () => app(ProductDocumentRequirementService::class);

        return $table->defaultSort('created_at', 'desc')->modifyQueryUsing(fn ($query) => $query->with(['product', 'documentType']))->columns([
            Tables\Columns\TextColumn::make('product.code')->label('Product')->searchable()->description(fn ($r) => $r->product ? 'v'.$r->product->version : null),
            Tables\Columns\TextColumn::make('kind')->badge(),
            Tables\Columns\TextColumn::make('product_type_code')->label('Product type')->placeholder('-'),
            Tables\Columns\TextColumn::make('stage')->placeholder('-'),
            Tables\Columns\TextColumn::make('document_type_id')->label('Document')->placeholder('-')->description(fn ($r) => $r->documentType?->name_fr),
            Tables\Columns\TextColumn::make('level')->badge()->placeholder('-'),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) { 'ACTIVE' => 'success', 'PENDING_APPROVAL' => 'warning', 'REJECTED' => 'danger', default => 'gray' }),
            Tables\Columns\TextColumn::make('reason')->wrap()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(['PENDING_APPROVAL' => 'Pending approval', 'ACTIVE' => 'Active', 'REJECTED' => 'Rejected', 'RETIRED' => 'Retired']),
            Tables\Filters\SelectFilter::make('kind')->options(['PRODUCT_TYPE' => 'Product type', 'MATRIX_OVERRIDE' => 'Matrix override']),
        ])->recordActions([
            Actions\Action::make('approve')->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                ->visible(fn ($r) => $r->status === 'PENDING_APPROVAL')
                ->action(fn ($r) => ServiceValidation::run(fn () => $svc()->approve($r, auth()->user())) && Notification::make()->title('Approved')->success()->send()),
            Actions\Action::make('reject')->icon(Heroicon::OutlinedXMark)->color('danger')->visible(fn ($r) => $r->status === 'PENDING_APPROVAL')
                ->schema([Forms\Components\Textarea::make('reason')->required()->minLength(5)])
                ->action(fn ($r, array $data) => ServiceValidation::run(fn () => $svc()->reject($r, auth()->user(), $data['reason']))),
            Actions\Action::make('retire')->icon(Heroicon::OutlinedArchiveBox)->color('gray')->visible(fn ($r) => $r->status === 'ACTIVE')
                ->schema([Forms\Components\Textarea::make('reason')->required()->minLength(5)])
                ->action(fn ($r, array $data) => ServiceValidation::run(fn () => $svc()->retire($r, auth()->user(), $data['reason']))),
        ])->emptyStateHeading('No product document requirements')
            ->emptyStateDescription('Products without a selected product type use the platform class packs.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListProductDocumentRequirements::route('/')];
    }
}
