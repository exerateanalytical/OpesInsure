<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\ClaimsWorkbench;

use App\Application\Claims\Adjusters\AdjusterWorkbench;
use BackedEnum;

/** CLP-002 / 003 my open assignments (feeds CLP-004 assignment details). */
final class MyAssignments extends AssignmentListPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-hard-hat';

    protected static ?int $navigationSort = 79;

    protected static ?string $slug = 'adjuster-workbench/assignments';

    protected static string $screen = 'assignments';

    protected static array $statuses = AdjusterWorkbench::OPEN;
}
