<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\DocumentGovernanceActions;
use App\Filament\Shared\Pages\GovernanceRegisterPage;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/** REQ-DOC-009 retention schedules (GET document-governance/retention-schedules): tenant + platform rows, maker-checker approval. */
final class RetentionSchedules extends GovernanceRegisterPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-archive';

    protected static ?int $navigationSort = 92;

    protected static ?string $slug = 'document-governance/retention-schedules';

    protected static array $permissions = ['documents.retention.manage', 'documents.retention.approve'];

    protected static string $screen = 'retention';

    protected const STATUSES = ['DRAFT', 'ACTIVE', 'RETIRED'];

    protected function query(string $tenantId)
    {
        return DB::table('retention_schedules')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->orderBy('code');
    }

    protected function columns(): array
    {
        return ['code', 'document_type_code', 'document_group', 'retention_years', 'trigger_event', 'disposition', 'status'];
    }

    protected function headerWorkflowActions(): array
    {
        return [DocumentGovernanceActions::retentionDraft()];
    }

    protected function recordWorkflowActions(): array
    {
        return [DocumentGovernanceActions::retentionApprove()];
    }
}
