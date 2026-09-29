<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\DocumentGovernanceActions;
use App\Filament\Shared\Pages\GovernanceRegisterPage;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/** REQ-DOC-010 intake queue (GET document-governance/intake). */
final class DocumentIntakeQueue extends GovernanceRegisterPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-inbox';

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'document-governance/intake';

    protected static array $permissions = ['documents.intake.manage'];

    protected static string $screen = 'intake';

    protected const STATUSES = ['RECEIVED', 'CLASSIFIED', 'EXCEPTION', 'REJECTED'];

    protected function query(string $tenantId)
    {
        return DB::table('document_intake_items')->where('tenant_id', $tenantId)->orderByDesc('received_at');
    }

    protected function columns(): array
    {
        return ['original_filename', 'channel', 'declared_type_code', 'suggested_type_code', 'classified_type_code', 'status', 'received_at'];
    }

    protected function headerWorkflowActions(): array
    {
        return [DocumentGovernanceActions::intakeReceive()];
    }

    protected function recordWorkflowActions(): array
    {
        return [DocumentGovernanceActions::intakeClassify(), DocumentGovernanceActions::intakeReject()];
    }
}
