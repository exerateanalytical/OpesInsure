<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CancellationRules\Pages;

use App\Filament\Admin\Resources\CancellationRules\CancellationRuleResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCancellationRule extends RecordDetailPage
{
    protected static string $resource = CancellationRuleResource::class;
}
