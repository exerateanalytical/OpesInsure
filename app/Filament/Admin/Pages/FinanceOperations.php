<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\FinanceOperationsActions as F;
use App\Filament\Shared\Actions\LedgerOperationsActions as L;
use App\Filament\Shared\Actions\WorkflowAction;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

/**
 * Finance operations desk (UI coverage batches 14-15): cashier sessions, FX, obligations, GL reference masters, payment
 * provider profiles, counterparty sub-ledger accounts, premium remittances, technical accounting and manual journals.
 * Every button is a WorkflowAction calling the API's own service with the API's permission; the page shows the queues
 * waiting on a decision. Visible to a holder of any of the batch permissions; the tenant is re-applied on each round-trip.
 */
final class FinanceOperations extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-briefcase-business';

    protected static string|\UnitEnum|null $navigationGroup = 'Financial operations';

    protected static ?int $navigationSort = 64;

    protected static ?string $slug = 'finance/operations';

    protected string $view = 'filament.admin.pages.finance-operations';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        foreach ([...F::PERMISSIONS, ...L::PERMISSIONS] as $p) {
            if (WorkflowAction::allowed($p)) {
                return true;
            }
        }

        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __(F::L.'.page.title');
    }

    public function getTitle(): string
    {
        return __(F::L.'.page.title');
    }

    public function getSubheading(): ?string
    {
        return __(F::L.'.page.subheading');
    }

    public function mount(): void
    {
        $this->tenantId = rescue(fn () => app(TenantContext::class)->id(), null, false);
    }

    public function hydrate(): void
    {
        if ($this->tenantId !== null) {
            app(TenantContext::class)->set($this->tenantId);
        }
    }

    protected function getHeaderActions(): array
    {
        $g = fn (string $key, string $icon, array $actions) => ActionGroup::make($actions)->label(__(F::L.'.groups.'.$key))->icon($icon)->button()->color('gray');

        return [
            $g('cash', 'lucide-wallet', [F::cashierSessionOpen(), F::cashierSessionCollect(), F::cashierSessionClose(), F::cashierSessionDecide(), F::fxRateRecord()]),
            $g('receivables', 'lucide-receipt', [F::allocationRulePublish(), F::obligationWriteOff(), F::obligationCancel(), F::agingConfigure(), F::subledgerDocumentGenerate()]),
            $g('reference', 'lucide-landmark', [F::controlAccountPropose(), F::controlAccountApprove(), F::costCentreCreate(), F::costCentreStatus(), F::institutionUpdate(),
                F::paymentProviderCreate(), F::paymentProviderUpdate(), F::paymentProviderSubmit(), F::paymentProviderDecide()]),
            $g('subledger', 'lucide-book-user', [F::counterpartyAccountOpen(), F::counterpartyAccountApprove(), F::counterpartyAccountStatus(),
                L::remittanceRecord(), L::remittanceAllocate(), L::remittanceHold()]),
            $g('technical', 'lucide-sigma', [L::actuarialImport(), L::actuarialApprove(), L::actuarialReject(), L::uprPost()]),
            $g('journals', 'lucide-book-open', [L::journalDraft(), L::journalValidate(), L::journalApprove(), L::journalReject(), L::journalPost(), L::journalReverse(), L::ledgerJournalReverse()]),
        ];
    }

    /** Work waiting on someone, per queue (tenant-scoped counts; the actions pick the records). */
    protected function getViewData(): array
    {
        $t = $this->tenantId;
        $count = fn (string $table, string|array $status) => $t === null ? 0 : DB::table($table)->where('tenant_id', $t)->whereIn('status', (array) $status)->count();

        return ['queues' => [
            'open_sessions' => $count('cashier_sessions', 'OPEN'),
            'sessions_to_decide' => $count('cashier_sessions', 'CLOSED'),
            'control_mappings_pending' => $count('gl_control_account_mappings', 'PENDING_APPROVAL'),
            'profiles_pending' => $count('payment_provider_profiles', 'PENDING_APPROVAL'),
            'accounts_pending' => $count('finance_counterparty_accounts', 'PENDING_APPROVAL'),
            'remittances_unapplied' => $count('premium_remittances', ['UNAPPLIED', 'PARTIALLY_APPLIED']),
            'imports_pending' => $count('technical_actuarial_imports', 'PENDING_APPROVAL'),
            'journals_draft' => $t === null ? 0 : DB::table('journals')->where('tenant_id', $t)->where('journal_type', 'MANUAL')->where('status', 'DRAFT')->count(),
            'journals_to_approve' => $t === null ? 0 : DB::table('journals')->where('tenant_id', $t)->where('journal_type', 'MANUAL')->where('status', 'VALIDATED')->count(),
            'journals_to_post' => $t === null ? 0 : DB::table('journals')->where('tenant_id', $t)->where('journal_type', 'MANUAL')->where('status', 'APPROVED')->count(),
        ]];
    }
}
