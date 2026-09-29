<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\DocumentGovernanceActions;
use App\Filament\Shared\Pages\GovernanceRegisterPage;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/** REQ-DOC-012 e-signature requests (staff side). */
final class SignatureRequests extends GovernanceRegisterPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-signature';

    protected static ?int $navigationSort = 95;

    protected static ?string $slug = 'document-governance/signature-requests';

    protected static array $permissions = ['documents.signatures.manage'];

    protected static string $screen = 'signatures';

    protected const STATUSES = ['PENDING', 'COMPLETED', 'DECLINED', 'EXPIRED', 'CANCELLED'];

    protected function query(string $tenantId)
    {
        return DB::table('signature_requests')->where('tenant_id', $tenantId)->orderByDesc('created_at');
    }

    protected function columns(): array
    {
        return ['document_id', 'provider', 'status', 'expires_at', 'created_at', 'completed_at'];
    }

    protected function headerWorkflowActions(): array
    {
        return [DocumentGovernanceActions::signatureRequest()];
    }

    protected function recordWorkflowActions(): array
    {
        return [DocumentGovernanceActions::signatureCancel()];
    }
}
