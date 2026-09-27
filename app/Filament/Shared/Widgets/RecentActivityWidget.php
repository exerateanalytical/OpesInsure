<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Latest audit_log entries of the tenant (the hash-chained audit trail written by AuditWriter). */
final class RecentActivityWidget extends RecordListWidget
{
    protected static ?int $sort = 5;

    protected static ?string $permission = 'audit.read';

    public function heading(): string
    {
        return __('dashboards.widgets.recent_activity');
    }

    protected function rows(string $tenantId, User $user): array
    {
        return DB::table('audit_log as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->where('a.tenant_id', $tenantId)
            ->orderByDesc('a.created_at')->limit(10)->get(['a.action', 'a.subject_type', 'u.full_name as actor', 'a.created_at'])->map(fn ($r) => (array) $r)->all();
    }

    protected function columns(): array
    {
        return ['action' => __('dashboards.columns.action'), 'subject_type' => __('dashboards.columns.subject'), 'actor' => __('dashboards.columns.actor'), 'created_at' => __('dashboards.columns.when')];
    }
}
