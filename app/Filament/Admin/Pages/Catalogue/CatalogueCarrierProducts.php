<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Catalogue;

use App\Filament\Shared\Actions\CatalogueActions;
use App\Models\Catalogue\CarrierProduct;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Carrier products & families (GET catalogue/carrier-products, catalogue.view); insurer users see only their carrier. */
final class CatalogueCarrierProducts extends CatalogueConfigPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-package';

    protected static ?int $navigationSort = 32;

    protected static ?string $slug = 'catalogue/carrier-products';

    protected static array $permissions = ['catalogue.view'];

    protected static string $screen = 'carrier_products';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => CarrierProduct::query()->with('family')->withCount('versions')
                ->when(CatalogueActions::carrierScope(), fn ($q, $c) => $q->where('carrier_id', $c))->orderBy('code'))
            ->columns([
                TextColumn::make('code')->label(self::col('code'))->searchable(),
                TextColumn::make('name.en')->label(self::col('name')),
                TextColumn::make('line_code')->label(self::col('line'))->badge(),
                TextColumn::make('family.code')->label(self::col('family')),
                TextColumn::make('customer_type')->label(self::col('customer_type')),
                TextColumn::make('versions_count')->label(self::col('versions')),
                TextColumn::make('status')->label(self::col('status'))->badge(),
            ])
            ->headerActions([CatalogueActions::createFamily(), CatalogueActions::createCarrierProduct()])
            ->recordActions([CatalogueActions::updateCarrierProduct(), CatalogueActions::newVersion()])
            ->emptyStateHeading(__('catalogue_actions.empty'));
    }
}
