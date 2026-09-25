<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierBrokerAgreements\Pages;

use App\Filament\Admin\Resources\CarrierBrokerAgreements\CarrierBrokerAgreementResource;
use Filament\Resources\Pages\ListRecords;

final class ListCarrierBrokerAgreements extends ListRecords
{
    protected static string $resource = CarrierBrokerAgreementResource::class;
}
