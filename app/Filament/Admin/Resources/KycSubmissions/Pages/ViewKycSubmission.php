<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\KycSubmissions\Pages;

use App\Filament\Admin\Resources\KycSubmissions\KycSubmissionResource;
use App\Filament\Shared\Actions\KycActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewKycSubmission extends RecordDetailPage
{
    protected static string $resource = KycSubmissionResource::class;

    protected static ?string $auditSubjectType = 'kyc_submission';

    protected function getHeaderActions(): array
    {
        return [KycActions::group()];
    }
}
