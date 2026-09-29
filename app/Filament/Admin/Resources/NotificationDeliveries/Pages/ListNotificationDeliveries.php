<?php namespace App\Filament\Admin\Resources\NotificationDeliveries\Pages;use App\Filament\Admin\Resources\NotificationDeliveries\NotificationDeliveryResource;use Filament\Resources\Pages\ListRecords;final class ListNotificationDeliveries extends ListRecords{protected static string $resource=NotificationDeliveryResource::class;
    protected function getHeaderActions(): array
    {
        return [\App\Filament\Shared\Actions\AccountSecurityActions::notificationQueue()];
    }
}
