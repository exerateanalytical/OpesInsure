<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\DocumentGovernanceActions;
use App\Filament\Shared\Pages\GovernanceRegisterPage;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/** REQ-DOC-009 destruction requests: requester asks, a different approver decides (DOCUMENT_DESTRUCTION case). */
final class DocumentDestructionRequests extends GovernanceRegisterPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-trash-2';

    protected static ?int $navigationSort = 94;

    protected static ?string $slug = 'document-governance/destruction-requests';

    protected static array $permissions = ['documents.destruction.request', 'documents.destruction.approve'];

    protected static string $screen = 'destruction';

    protected const STATUSES = ['PENDING', 'APPROVED', 'REJECTED', 'DESTROYED', 'BLOCKED'];

    protected function query(string $tenantId)
    {
        return DB::table('document_destruction_requests')->where('tenant_id', $tenantId)->orderByDesc('created_at');
    }

    protected function columns(): array
    {
        return ['document_id', 'reason', 'status', 'created_at', 'decided_at', 'decision_note'];
    }

    protected function headerWorkflowActions(): array
    {
        return [DocumentGovernanceActions::destructionRequest()];
    }

    protected function recordWorkflowActions(): array
    {
        return [DocumentGovernanceActions::destructionDecide()];
    }
}
