<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LegacyMigrations\Pages;

use App\Filament\Admin\Resources\LegacyMigrations\LegacyMigrationResource;
use App\Filament\Shared\Actions\LegacyMigrationActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewLegacyMigration extends RecordDetailPage
{
    protected static string $resource = LegacyMigrationResource::class;

    protected static ?string $auditSubjectType = 'import_batch';

    protected function getHeaderActions(): array
    {
        return [LegacyMigrationActions::map(), LegacyMigrationActions::validate(), LegacyMigrationActions::dryRun(), LegacyMigrationActions::reconcile(),
            LegacyMigrationActions::submit(), LegacyMigrationActions::approve(), LegacyMigrationActions::reject(), LegacyMigrationActions::commit(), LegacyMigrationActions::rollback()];
    }
}
