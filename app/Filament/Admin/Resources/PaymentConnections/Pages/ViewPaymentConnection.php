<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentConnections\Pages;

use App\Filament\Admin\Resources\PaymentConnections\PaymentConnectionResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewPaymentConnection extends RecordDetailPage
{
    protected static string $resource = PaymentConnectionResource::class;
}
