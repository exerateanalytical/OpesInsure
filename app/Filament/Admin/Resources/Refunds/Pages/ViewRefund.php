<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Refunds\Pages;

use App\Filament\Admin\Resources\Refunds\RefundResource;
use App\Filament\Shared\Actions\RefundActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewRefund extends RecordDetailPage
{
    protected static string $resource = RefundResource::class;

    protected static ?string $auditSubjectType = 'refund';

    protected function getHeaderActions(): array
    {
        return [RefundActions::group()];
    }
}
