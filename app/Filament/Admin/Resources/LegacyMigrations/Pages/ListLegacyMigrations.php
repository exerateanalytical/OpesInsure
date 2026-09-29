<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LegacyMigrations\Pages;

use App\Filament\Admin\Resources\LegacyMigrations\LegacyMigrationResource;
use App\Filament\Shared\Actions\LegacyMigrationActions;
use Filament\Resources\Pages\ListRecords;

final class ListLegacyMigrations extends ListRecords
{
    use \App\Filament\Shared\Concerns\OpensViewPage;

    protected static string $resource = LegacyMigrationResource::class;

    protected function getHeaderActions(): array
    {
        return [LegacyMigrationActions::stage()];
    }
}
