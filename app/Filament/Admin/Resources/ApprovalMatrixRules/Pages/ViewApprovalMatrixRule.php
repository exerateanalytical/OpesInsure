<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ApprovalMatrixRules\Pages;

use App\Filament\Admin\Resources\ApprovalMatrixRules\ApprovalMatrixRuleResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewApprovalMatrixRule extends RecordDetailPage
{
    protected static string $resource = ApprovalMatrixRuleResource::class;
}
