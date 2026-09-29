<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Catalogue;

use App\Filament\Shared\Actions\ReferenceConfigActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Versioned regulatory reference sets (configuration/regulatory-reference-sets); maker drafts, checker activates. */
final class RegulatoryReferenceSets extends CatalogueConfigPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-book-marked';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'configuration/regulatory-reference-sets';

    protected static array $permissions = ['configuration.regulatory.manage', 'configuration.regulatory.approve'];

    protected static string $screen = 'regulatory_reference_sets';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => DB::table('regulatory_reference_sets')->orderBy('jurisdiction')->orderBy('code')->orderByDesc('version')->get()
                ->mapWithKeys(fn ($r) => [$r->id => ['__key' => $r->id, 'id' => $r->id, 'jurisdiction' => $r->jurisdiction, 'code' => $r->code, 'version' => $r->version,
                    'status' => $r->status, 'effective_from' => $r->effective_from, 'effective_until' => $r->effective_until, 'content_hash' => substr((string) $r->content_hash, 0, 12)]])->all())
            ->columns([
                TextColumn::make('jurisdiction')->label(self::col('jurisdiction')),
                TextColumn::make('code')->label(self::col('code')),
                TextColumn::make('version')->label(self::col('version')),
                TextColumn::make('effective_from')->label(self::col('effective_from'))->date(),
                TextColumn::make('effective_until')->label(self::col('effective_until'))->date(),
                TextColumn::make('content_hash')->label(self::col('hash')),
                TextColumn::make('status')->label(self::col('status'))->badge(),
            ])
            ->headerActions([ReferenceConfigActions::rrsDraft()])
            ->recordActions([ReferenceConfigActions::rrsApprove()])
            ->emptyStateHeading(__('catalogue_actions.empty'));
    }
}
