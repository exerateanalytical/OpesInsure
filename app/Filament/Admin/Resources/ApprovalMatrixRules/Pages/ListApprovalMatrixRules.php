<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ApprovalMatrixRules\Pages;

use App\Filament\Admin\Resources\ApprovalMatrixRules\ApprovalMatrixRuleResource;
use Filament\Resources\Pages\ListRecords;

final class ListApprovalMatrixRules extends ListRecords
{
    protected static string $resource = ApprovalMatrixRuleResource::class;
}
