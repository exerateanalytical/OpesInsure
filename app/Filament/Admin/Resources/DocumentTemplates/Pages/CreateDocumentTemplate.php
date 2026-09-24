<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentTemplates\Pages;

use App\Filament\Admin\Resources\DocumentTemplates\DocumentTemplateResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateDocumentTemplate extends CreateRecord
{
    protected static string $resource = DocumentTemplateResource::class;

    use \App\Filament\Admin\Concerns\NotifiesServiceValidationErrors;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return app(\App\Application\Documents\Engine\DocumentTemplateService::class)->createDraft($data, auth()->user());
    }
}
