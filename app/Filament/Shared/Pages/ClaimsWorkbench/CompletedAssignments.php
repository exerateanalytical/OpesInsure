<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\ClaimsWorkbench;

use App\Application\Claims\Adjusters\AdjusterWorkbench;
use BackedEnum;

/** CLP-017 completed assignments: report accepted, declined or cancelled (read-only; opens the assignment file). */
final class CompletedAssignments extends AssignmentListPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-archive';

    protected static ?int $navigationSort = 81;

    protected static ?string $slug = 'adjuster-workbench/completed';

    protected static string $screen = 'completed';

    protected static array $statuses = AdjusterWorkbench::COMPLETED;
}
