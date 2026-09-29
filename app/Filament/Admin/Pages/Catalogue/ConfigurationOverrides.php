<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Catalogue;

use App\Application\Configuration\ConfigurationInheritance;
use App\Filament\Shared\Actions\ReferenceConfigActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Inheritable configuration keys (GET configuration/inheritance, configuration.changes.manage) and override drafts. */
final class ConfigurationOverrides extends CatalogueConfigPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-sliders-horizontal';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 81;

    protected static ?string $slug = 'configuration/inheritance';

    protected static array $permissions = ['configuration.changes.manage'];

    protected static string $screen = 'configuration_overrides';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => collect(app(ConfigurationInheritance::class)->keys())
                ->mapWithKeys(fn ($k, $key) => [$key => ['__key' => $key, 'id' => $key, 'key' => $key, 'default' => json_encode($k['default'] ?? null, JSON_UNESCAPED_UNICODE),
                    'restriction' => $k['restriction'] ?? null, 'overridable_at' => implode(', ', $k['overridable_at'] ?? [])]])->all())
            ->columns([
                TextColumn::make('key')->label(self::col('key')),
                TextColumn::make('default')->label(self::col('default')),
                TextColumn::make('restriction')->label(self::col('restriction'))->badge(),
                TextColumn::make('overridable_at')->label(self::col('overridable_at'))->wrap(),
            ])
            ->recordActions([ReferenceConfigActions::cfgDraftOverride()])
            ->emptyStateHeading(__('catalogue_actions.empty'));
    }
}
