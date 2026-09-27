<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Application\Cases\Bridges\MyWorkProjection;
use App\Models\User;

/** "My work" queue of the signed-in user (MyWorkProjection: case tasks, owned cases, follow-ups, legacy work items), earliest due first. */
final class MyWorkWidget extends RecordListWidget
{
    protected static ?int $sort = 2;

    public function heading(): string
    {
        return __('dashboards.widgets.my_work');
    }

    protected function rows(string $tenantId, User $user): array
    {
        return app(MyWorkProjection::class)->for($user, $tenantId);
    }

    protected function columns(): array
    {
        return ['title' => __('dashboards.columns.title'), 'kind' => __('dashboards.columns.kind'), 'status' => __('dashboards.columns.status'), 'due_at' => __('dashboards.columns.due_at')];
    }
}
