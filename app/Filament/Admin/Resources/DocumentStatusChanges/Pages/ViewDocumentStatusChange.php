<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentStatusChanges\Pages;

use App\Filament\Admin\Resources\DocumentStatusChanges\DocumentStatusChangeResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewDocumentStatusChange extends RecordDetailPage
{
    protected static string $resource = DocumentStatusChangeResource::class;
}
