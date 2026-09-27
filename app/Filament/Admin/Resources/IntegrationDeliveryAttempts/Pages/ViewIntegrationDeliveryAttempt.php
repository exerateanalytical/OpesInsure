<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\IntegrationDeliveryAttempts\Pages;

use App\Filament\Admin\Resources\IntegrationDeliveryAttempts\IntegrationDeliveryAttemptResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewIntegrationDeliveryAttempt extends RecordDetailPage
{
    protected static string $resource = IntegrationDeliveryAttemptResource::class;
}
