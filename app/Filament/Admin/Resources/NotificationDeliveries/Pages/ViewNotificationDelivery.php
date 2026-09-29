<?php namespace App\Filament\Admin\Resources\NotificationDeliveries\Pages;use App\Filament\Admin\Resources\NotificationDeliveries\NotificationDeliveryResource;use App\Filament\Shared\Pages\RecordDetailPage;final class ViewNotificationDelivery extends RecordDetailPage{protected static string $resource=NotificationDeliveryResource::class;
    protected function getHeaderActions(): array
    {
        return [\App\Filament\Shared\Actions\AccountSecurityActions::notificationRetry(), \App\Filament\Shared\Actions\AccountSecurityActions::notificationCancel()];
    }
}
