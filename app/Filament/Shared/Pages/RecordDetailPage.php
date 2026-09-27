<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Filament\Shared\Components\RecordInfolist;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

/**
 * Shared read-only detail page for configuration / reference-data resources.
 * Subclasses only declare $resource (and optionally $auditSubjectType).
 */
abstract class RecordDetailPage extends ViewRecord
{
    /** audit_log subject_type; defaults to snake_case model basename. */
    protected static ?string $auditSubjectType = null;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components(RecordInfolist::for($this->getRecord(), static::$auditSubjectType));
    }

    protected function getHeaderActions(): array
    {
        return static::getResource()::hasPage('edit') ? [EditAction::make()] : [];
    }
}
