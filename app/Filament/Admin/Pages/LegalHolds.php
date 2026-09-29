<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\DocumentGovernanceActions;
use App\Filament\Shared\Pages\GovernanceRegisterPage;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/** REQ-DOC-009 legal holds (GET document-governance/legal-holds). */
final class LegalHolds extends GovernanceRegisterPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-lock';

    protected static ?int $navigationSort = 93;

    protected static ?string $slug = 'document-governance/legal-holds';

    protected static array $permissions = ['documents.legal_hold.manage'];

    protected static string $screen = 'legal_holds';

    protected function query(string $tenantId)
    {
        return DB::table('legal_holds')->where('tenant_id', $tenantId)->orderByDesc('placed_at');
    }

    protected function columns(): array
    {
        return ['subject_type', 'subject_id', 'reason_code', 'hold_until', 'placed_at', 'released_at'];
    }

    protected function headerWorkflowActions(): array
    {
        return [DocumentGovernanceActions::holdPlace()];
    }

    protected function recordWorkflowActions(): array
    {
        return [DocumentGovernanceActions::holdRelease()];
    }
}
