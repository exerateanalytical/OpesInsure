<?php namespace App\Filament\Admin\Resources\SupportTickets\Pages;use App\Filament\Admin\Resources\SupportTickets\SupportTicketResource;use App\Filament\Shared\Pages\RecordDetailPage;final class ViewSupportTicket extends RecordDetailPage{protected static string $resource=SupportTicketResource::class;
    protected function getHeaderActions(): array
    {
        return \App\Filament\Shared\Actions\SupportActions::ticketViewHeader();
    }
}
