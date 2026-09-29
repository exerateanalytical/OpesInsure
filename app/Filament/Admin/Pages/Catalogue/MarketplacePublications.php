<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Catalogue;

use App\Filament\Shared\Actions\ReferenceConfigActions;
use App\Models\MarketplacePublication;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Web marketplace publications of product versions (MarketplacePublicationPolicy view); publish + independent approval. */
final class MarketplacePublications extends CatalogueConfigPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-store';

    protected static ?int $navigationSort = 37;

    protected static ?string $slug = 'catalogue/marketplace-publications';

    protected static array $permissions = ['marketplace.publications.view', 'marketplace.publications.create', 'marketplace.publications.approve'];

    protected static string $screen = 'marketplace_publications';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => MarketplacePublication::query()->where('tenant_id', $this->tenantId)->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('product_id')->label(self::col('product'))
                    ->formatStateUsing(fn ($state) => ($p = \App\Models\InsuranceProduct::find($state)) ? $p->code.' v'.$p->version : $state),
                TextColumn::make('channels')->label(self::col('channels'))->badge(),
                TextColumn::make('starts_at')->label(self::col('starts_at'))->dateTime(),
                TextColumn::make('ends_at')->label(self::col('ends_at'))->dateTime(),
                TextColumn::make('version')->label(self::col('version')),
                TextColumn::make('status')->label(self::col('status'))->badge(),
            ])
            ->headerActions([ReferenceConfigActions::mpPublish()])
            ->recordActions([ReferenceConfigActions::mpApprove()])
            ->emptyStateHeading(__('catalogue_actions.empty'));
    }
}
