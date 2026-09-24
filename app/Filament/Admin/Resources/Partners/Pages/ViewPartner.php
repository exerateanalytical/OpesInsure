<?php
namespace App\Filament\Admin\Resources\Partners\Pages;

use App\Application\Partners\PartnerStatusService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\Partners\PartnerResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

final class ViewPartner extends ViewRecord
{
    protected static string $resource = PartnerResource::class;

    /** Lets an administrator activate a PENDING partner (or suspend/reject one). */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeStatus')->label('Change status')->icon('heroicon-o-arrows-right-left')
                ->visible(fn () => auth()->user()?->memberships()->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'])->exists())
                ->schema([
                    Select::make('status')->options(['ACTIVE' => 'Active', 'SUSPENDED' => 'Suspended', 'REJECTED' => 'Rejected', 'PENDING' => 'Pending licence review'])->required()->native(false),
                    Textarea::make('notes')->required()->minLength(5)->maxLength(2000),
                ])
                ->action(function (array $d) {
                    ServiceValidation::run(fn () => app(PartnerStatusService::class)->transition($this->record, $d['status'], $d['notes'], auth()->user()));
                    $this->record->refresh();
                }),
        ];
    }
}
