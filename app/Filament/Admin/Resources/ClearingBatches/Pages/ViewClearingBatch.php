<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ClearingBatches\Pages;

use App\Filament\Admin\Resources\ClearingBatches\ClearingBatchResource;
use App\Filament\Shared\Actions\ClearingActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewClearingBatch extends RecordDetailPage
{
    protected static string $resource = ClearingBatchResource::class;

    protected static ?string $auditSubjectType = 'clearing_batch';

    protected function getHeaderActions(): array
    {
        return [ClearingActions::attach(), ClearingActions::settle(), ClearingActions::reconcile()];
    }
}
