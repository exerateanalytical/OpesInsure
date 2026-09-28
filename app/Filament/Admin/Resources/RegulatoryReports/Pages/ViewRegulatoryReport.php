<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RegulatoryReports\Pages;

use App\Application\Compliance\RegulatoryReportingService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\RegulatoryReports\RegulatoryReportRunResource;
use App\Filament\Shared\Actions\ComplianceActions;
use App\Filament\Shared\Pages\RecordDetailPage;
use Filament\Actions\Action;

final class ViewRegulatoryReport extends RecordDetailPage
{
    protected static string $resource = RegulatoryReportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')->visible(fn () => $this->record->status === 'DRAFT')
                ->action(function () {
                    ServiceValidation::run(fn () => app(RegulatoryReportingService::class)->approve($this->record, auth()->user()));
                }),
            Action::make('submit')->visible(fn () => in_array($this->record->status, ['APPROVED', 'RETRY_PENDING'], true))
                ->action(function () {
                    ServiceValidation::run(fn () => app(RegulatoryReportingService::class)->submit($this->record));
                }),
            ComplianceActions::reportAcknowledge(),
            ComplianceActions::reportFail(),
        ];
    }
}
