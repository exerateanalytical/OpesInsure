<?php
namespace App\Filament\Admin\Resources\PaymentConnections\Pages;use App\Filament\Admin\Resources\PaymentConnections\PaymentConnectionResource;use Filament\Resources\Pages\ListRecords;final class ListPaymentConnections extends ListRecords{
    use \App\Filament\Shared\Concerns\OpensViewPage;
protected static string $resource=PaymentConnectionResource::class;}
