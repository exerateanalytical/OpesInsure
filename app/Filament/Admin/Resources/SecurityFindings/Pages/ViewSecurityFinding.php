<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SecurityFindings\Pages;

use App\Filament\Admin\Resources\SecurityFindings\SecurityFindingResource;
use App\Filament\Shared\Actions\AccountSecurityActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewSecurityFinding extends RecordDetailPage
{
    protected static string $resource = SecurityFindingResource::class;

    protected static ?string $auditSubjectType = 'security_finding';

    protected function getHeaderActions(): array
    {
        return [AccountSecurityActions::findingTransition()];
    }
}
