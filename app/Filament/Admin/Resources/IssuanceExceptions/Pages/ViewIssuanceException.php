<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\IssuanceExceptions\Pages;

use App\Filament\Admin\Resources\IssuanceExceptions\IssuanceExceptionResource;
use App\Filament\Shared\Actions\IssuanceActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewIssuanceException extends RecordDetailPage
{
    protected static string $resource = IssuanceExceptionResource::class;

    protected static ?string $auditSubjectType = 'issuance_exception';

    protected function getHeaderActions(): array
    {
        return [IssuanceActions::exceptionGroup()];
    }
}
