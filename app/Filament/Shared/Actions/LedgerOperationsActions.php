<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Audit\AuditWriter;
use App\Application\Ledger\Journals\ManualJournalService;
use App\Application\Ledger\LedgerService;
use App\Application\Ledger\Technical\ActuarialImportService;
use App\Application\Ledger\Technical\UprPostingService;
use App\Application\Finance\Subledger\PremiumRemittanceService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Remittances, technical accounting and manual journals (UI coverage batch 15). Same services, same permissions, same
 * validation as the API; nothing here posts to the ledger directly — posting stays in the services (maker-checker on
 * manual journals and actuarial imports, open-period guard, balanced lines, reversal by mirror journal only).
 *   remittanceRecord    POST finance/subledger/remittances                       finance.settlements.create            PremiumRemittanceService::record
 *   remittanceAllocate  POST finance/subledger/remittances/{r}/allocations       finance.settlements.create            PremiumRemittanceService::allocate
 *   remittanceHold      POST finance/subledger/remittances/{r}/hold              finance.reconciliation.override       PremiumRemittanceService::hold
 *   actuarialImport     POST finance/technical/actuarial-imports                 technical_accounting.actuarial.import ActuarialImportService::import
 *   actuarialApprove    POST finance/technical/actuarial-imports/{i}/approve     technical_accounting.actuarial.approve ActuarialImportService::approve
 *   actuarialReject     POST finance/technical/actuarial-imports/{i}/reject      technical_accounting.actuarial.approve ActuarialImportService::reject
 *   uprPost             POST finance/technical/upr-postings                      technical_accounting.upr.post         UprPostingService::post
 *   journalDraft        POST ledger/manual-journals (+ deprecated ledger/journals) ledger.adjust                       ManualJournalService::createDraft
 *   journalValidate     POST ledger/manual-journals/{j}/validate                 ledger.adjust                         ManualJournalService::validate
 *   journalApprove      POST ledger/manual-journals/{j}/approve                  ledger.approve                        ManualJournalService::approve
 *   journalReject       POST ledger/manual-journals/{j}/reject                   ledger.approve                        ManualJournalService::reject
 *   journalPost         POST ledger/manual-journals/{j}/post                     ledger.post                           ManualJournalService::post
 *   journalReverse      POST ledger/manual-journals/{j}/reverse                  ledger.reverse                        ManualJournalService::reverse
 *   ledgerJournalReverse POST ledger/journals/{j}/reverse (system journals)      ledger.reverse                        LedgerService::reverse
 */
final class LedgerOperationsActions
{
    public const PERMISSIONS = [
        'finance.settlements.create', 'finance.reconciliation.override', 'technical_accounting.actuarial.import', 'technical_accounting.actuarial.approve',
        'technical_accounting.upr.post', 'ledger.adjust', 'ledger.approve', 'ledger.post', 'ledger.reverse',
    ];

    // ---- premium remittances ----------------------------------------------------------------------------------

    public static function remittanceRecord(): Action
    {
        $p = 'finance.settlements.create';

        return WorkflowAction::make('remittanceRecord', $p, FinanceOperationsActions::L)->icon('lucide-banknote')
            ->schema([
                Select::make('insurer_id')->label(self::f('insurer'))->options(fn () => FinanceOptions::carriers())->searchable()->required(),
                TextInput::make('broker_id')->label(self::f('broker_id_optional'))->uuid(),
                Select::make('currency')->label(self::f('currency'))->options(FinanceOptions::currencies())->default('XAF')->required(),
                TextInput::make('amount_minor')->label(self::f('amount_minor'))->integer()->minValue(1)->required(),
                DatePicker::make('remittance_date')->label(self::f('remittance_date')),
                TextInput::make('payment_reference')->label(self::f('payment_reference'))->maxLength(120),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(PremiumRemittanceService::class)->record(
                FinanceOperationsActions::tenant(),
                FinanceOperationsActions::filled($data, ['insurer_id', 'broker_id', 'currency', 'remittance_date', 'payment_reference'])
                    + ['amount_minor' => (int) $data['amount_minor'], 'idempotency_key' => 'web-'.Str::uuid()],
                (string) auth()->id()), FinanceOperationsActions::done('remittanceRecord')));
    }

    public static function remittanceAllocate(): Action
    {
        $p = 'finance.settlements.create';

        return WorkflowAction::make('remittanceAllocate', $p, FinanceOperationsActions::L)->icon('lucide-split')
            ->schema([
                FinanceOperationsActions::pick('remittance_id', 'remittance', fn () => self::remittances(['UNAPPLIED', 'PARTIALLY_APPLIED'])),
                Repeater::make('allocations')->label(self::f('allocations'))->minItems(1)->required()->schema([
                    Select::make('financial_obligation_id')->label(self::f('payable'))->options(fn () => FinanceOperationsActions::obligations(fn ($q) => $q->where('kind', 'PAYABLE')->where('creditor_type', 'carrier')))->searchable()->required(),
                    TextInput::make('amount_minor')->label(self::f('amount_minor'))->integer()->minValue(1)->required(),
                ]),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(PremiumRemittanceService::class)->allocate(
                FinanceOperationsActions::tenant(), $data['remittance_id'],
                array_values(array_map(fn ($a) => ['financial_obligation_id' => $a['financial_obligation_id'], 'amount_minor' => (int) $a['amount_minor']], $data['allocations'])),
                (string) auth()->id()), FinanceOperationsActions::done('remittanceAllocate')));
    }

    public static function remittanceHold(): Action
    {
        $p = 'finance.reconciliation.override';

        return WorkflowAction::make('remittanceHold', $p, FinanceOperationsActions::L)->icon('lucide-pause')->color('warning')
            ->schema([
                FinanceOperationsActions::pick('remittance_id', 'remittance', fn () => self::remittances(['UNAPPLIED', 'PARTIALLY_APPLIED', 'APPLIED', 'DISPUTED', 'RECONCILIATION_HOLD'])),
                Select::make('status')->label(self::f('hold_status'))->options(FinanceOperationsActions::codes(['DISPUTED', 'RECONCILIATION_HOLD', 'RELEASE'], 'hold'))->required(),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(500),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(PremiumRemittanceService::class)->hold(
                FinanceOperationsActions::tenant(), $data['remittance_id'], $data['status'], $data['reason'], (string) auth()->id()), FinanceOperationsActions::done('remittanceHold')));
    }

    // ---- technical accounting ---------------------------------------------------------------------------------

    public static function actuarialImport(): Action
    {
        $p = 'technical_accounting.actuarial.import';

        return WorkflowAction::make('actuarialImport', $p, FinanceOperationsActions::L)->icon('lucide-file-up')
            ->schema([
                Select::make('kind')->label(self::f('actuarial_kind'))->options(FinanceOperationsActions::opts(ActuarialImportService::KINDS))->required(),
                DatePicker::make('period_end')->label(self::f('period_end'))->required(),
                TextInput::make('source')->label(self::f('actuarial_source'))->required()->maxLength(120),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(2000),
                Repeater::make('values')->label(self::f('actuarial_values'))->minItems(1)->maxItems(5000)->required()->schema([
                    Select::make('carrier_id')->label(self::f('insurer_optional'))->options(fn () => FinanceOptions::carriers())->searchable(),
                    TextInput::make('line_code')->label(self::f('line_code'))->maxLength(64),
                    TextInput::make('metric')->label(self::f('metric'))->required()->maxLength(48)->regex('/^[A-Za-z0-9_]+$/'),
                    TextInput::make('amount_minor')->label(self::f('amount_minor_signed'))->integer()->required(),
                    Select::make('currency')->label(self::f('currency'))->options(FinanceOptions::currencies())->default('XAF')->required(),
                ])->columns(5),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(ActuarialImportService::class)->import(
                FinanceOperationsActions::tenant(), $data['kind'], CarbonImmutable::parse($data['period_end'])->toDateString(), $data['source'],
                array_values(array_map(fn ($v) => ['carrier_id' => ($v['carrier_id'] ?? null) ?: null, 'line_code' => ($v['line_code'] ?? null) ?: null, 'metric' => $v['metric'],
                    'amount_minor' => (int) $v['amount_minor'], 'currency' => $v['currency']], $data['values'])),
                (string) auth()->id(), filled($data['notes'] ?? null) ? $data['notes'] : null), FinanceOperationsActions::done('actuarialImport')));
    }

    public static function actuarialApprove(): Action
    {
        $p = 'technical_accounting.actuarial.approve';

        return WorkflowAction::make('actuarialApprove', $p, FinanceOperationsActions::L)->icon('lucide-badge-check')->color('success')
            ->schema([FinanceOperationsActions::pick('import_id', 'actuarial_import', fn () => self::imports())])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(ActuarialImportService::class)->approve(
                FinanceOperationsActions::tenant(), $data['import_id'], (string) auth()->id()), FinanceOperationsActions::done('actuarialApprove')));
    }

    public static function actuarialReject(): Action
    {
        $p = 'technical_accounting.actuarial.approve';

        return WorkflowAction::make('actuarialReject', $p, FinanceOperationsActions::L)->icon('lucide-circle-x')->color('danger')
            ->schema([
                FinanceOperationsActions::pick('import_id', 'actuarial_import', fn () => self::imports()),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(1000),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(ActuarialImportService::class)->reject(
                FinanceOperationsActions::tenant(), $data['import_id'], (string) auth()->id(), $data['reason']), FinanceOperationsActions::done('actuarialReject')));
    }

    public static function uprPost(): Action
    {
        $p = 'technical_accounting.upr.post';

        return WorkflowAction::make('uprPost', $p, FinanceOperationsActions::L)->icon('lucide-calendar-check')->requiresConfirmation()
            ->schema([DatePicker::make('period_end')->label(self::f('period_end'))->maxDate(now())->required()])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $end = CarbonImmutable::parse($data['period_end']);
                abort_if($end->isAfter(CarbonImmutable::today()), 422, __(FinanceOperationsActions::L.'.uprPost.future'));

                return app(UprPostingService::class)->post(FinanceOperationsActions::tenant(), $end, (string) auth()->id(), (string) Str::uuid());
            }, FinanceOperationsActions::done('uprPost')));
    }

    // ---- manual journals (maker-checker lifecycle) -----------------------------------------------------------

    public static function journalDraft(): Action
    {
        $p = 'ledger.adjust';

        return WorkflowAction::make('journalDraft', $p, FinanceOperationsActions::L)->icon('lucide-notebook-pen')
            ->schema([
                TextInput::make('reference_type')->label(self::f('reference_type'))->default('MANUAL_ADJUSTMENT')->required()->maxLength(64),
                TextInput::make('reference_id')->label(self::f('reference_id'))->uuid()->default(fn () => (string) Str::uuid())->required(),
                Select::make('currency')->label(self::f('currency'))->options(FinanceOptions::currencies())->default('XAF')->required(),
                TextInput::make('reason_code')->label(self::f('reason_code'))->required()->maxLength(64),
                TextInput::make('description')->label(self::f('description'))->maxLength(500),
                DatePicker::make('journal_date')->label(self::f('journal_date')),
                Repeater::make('lines')->label(self::f('journal_lines'))->minItems(2)->maxItems(50)->required()->schema([
                    Select::make('account_id')->label(self::f('ledger_account'))->options(fn () => self::ledgerAccounts())->searchable()->required(),
                    TextInput::make('debit_minor')->label(self::f('debit_minor'))->integer()->minValue(0)->default(0),
                    TextInput::make('credit_minor')->label(self::f('credit_minor'))->integer()->minValue(0)->default(0),
                ])->columns(3)->defaultItems(2),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $tenant = FinanceOperationsActions::tenant();
                // Same account rule as the controller (exists:ledger_accounts) plus tenant scope; balancing stays in validate().
                $lines = array_values(array_map(fn ($l) => ['account_id' => $l['account_id'], 'debit_minor' => (int) ($l['debit_minor'] ?? 0), 'credit_minor' => (int) ($l['credit_minor'] ?? 0)], $data['lines']));
                foreach ($lines as $l) {
                    DB::table('ledger_accounts')->where('id', $l['account_id'])->where(fn ($q) => $q->where('tenant_id', $tenant)->orWhereNull('tenant_id'))->exists() || abort(422, __(FinanceOperationsActions::L.'.journalDraft.unknown_account'));
                }
                $d = FinanceOperationsActions::filled($data, ['reference_type', 'reference_id', 'reason_code', 'description']) + ['currency' => strtoupper($data['currency']), 'lines' => $lines];
                if (filled($data['journal_date'] ?? null)) {
                    $d['journal_date'] = CarbonImmutable::parse($data['journal_date'])->toDateString();
                }

                return app(ManualJournalService::class)->createDraft($tenant, (string) auth()->id(), $d, (string) Str::uuid());
            }, FinanceOperationsActions::done('journalDraft')));
    }

    public static function journalValidate(): Action
    {
        return self::journalStep('journalValidate', 'ledger.adjust', ['DRAFT'], 'lucide-list-checks', fn (string $t, string $j) => app(ManualJournalService::class)->validate($t, $j, (string) auth()->id()));
    }

    public static function journalApprove(): Action
    {
        return self::journalStep('journalApprove', 'ledger.approve', ['VALIDATED'], 'lucide-badge-check', fn (string $t, string $j) => app(ManualJournalService::class)->approve($t, $j, (string) auth()->id()));
    }

    public static function journalPost(): Action
    {
        return self::journalStep('journalPost', 'ledger.post', ['APPROVED'], 'lucide-book-check', fn (string $t, string $j) => app(ManualJournalService::class)->post($t, $j, (string) auth()->id()));
    }

    public static function journalReject(): Action
    {
        return self::journalStep('journalReject', 'ledger.approve', ['VALIDATED', 'APPROVED'], 'lucide-circle-x',
            fn (string $t, string $j, array $d) => app(ManualJournalService::class)->reject($t, $j, (string) auth()->id(), $d['reason_code']),
            [TextInput::make('reason_code')->label(self::f('reason_code'))->required()->maxLength(64)]);
    }

    public static function journalReverse(): Action
    {
        return self::journalStep('journalReverse', 'ledger.reverse', ['POSTED'], 'lucide-undo-2',
            fn (string $t, string $j, array $d) => app(ManualJournalService::class)->reverse($t, $j, (string) auth()->id(), $d['reason_code'], (string) Str::uuid()),
            self::reversalFields());
    }

    /** System (non-manual) posted journals: the deprecated ledger/journals/{j}/reverse route — mirror journal via LedgerService, audited like the controller. */
    public static function ledgerJournalReverse(): Action
    {
        $p = 'ledger.reverse';

        return WorkflowAction::make('ledgerJournalReverse', $p, FinanceOperationsActions::L)->icon('lucide-undo-2')->color('danger')
            ->schema([FinanceOperationsActions::pick('journal_id', 'journal', fn () => self::journals(['POSTED'], false)), ...self::reversalFields()])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                self::ownedJournal($data['journal_id'], false);
                $id = app(LedgerService::class)->reverse($data['journal_id'], (string) Str::uuid());
                app(AuditWriter::class)->record('ledger.reversed', 'journal', $data['journal_id'], ['reversal_journal_id' => $id], $data['reason_code']);

                return $id;
            }, FinanceOperationsActions::done('ledgerJournalReverse')));
    }

    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::remittanceRecord(), self::remittanceAllocate(), self::remittanceHold(), self::actuarialImport(), self::actuarialApprove(), self::actuarialReject(), self::uprPost(),
            self::journalDraft(), self::journalValidate(), self::journalApprove(), self::journalReject(), self::journalPost(), self::journalReverse(), self::ledgerJournalReverse(),
        ];
    }

    /** @param list<string> $statuses */
    private static function journalStep(string $name, string $p, array $statuses, string $icon, callable $call, array $extra = []): Action
    {
        return WorkflowAction::make($name, $p, FinanceOperationsActions::L)->icon($icon)
            ->schema([FinanceOperationsActions::pick('journal_id', 'manual_journal', fn () => self::journals($statuses, true)), ...$extra])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => $call(FinanceOperationsActions::tenant(), $data['journal_id'], $data), FinanceOperationsActions::done($name)));
    }

    private static function reversalFields(): array
    {
        return [
            TextInput::make('reason_code')->label(self::f('reason_code'))->required()->maxLength(64),
            Textarea::make('notes')->label(self::f('reversal_notes'))->required()->minLength(20)->maxLength(2000),
        ];
    }

    private static function ownedJournal(string $id, bool $manual): void
    {
        $j = DB::table('journals')->where('tenant_id', FinanceOperationsActions::tenant())->where('id', $id)->first();
        abort_unless($j && (($j->journal_type ?? null) === 'MANUAL') === $manual, 404);
    }

    private static function f(string $key): string
    {
        return FinanceOperationsActions::f($key);
    }

    /** @param list<string> $statuses @return array<string, string> */
    private static function journals(array $statuses, bool $manual): array
    {
        $q = DB::table('journals')->where('tenant_id', FinanceOperationsActions::tenant())->whereIn('status', $statuses);
        $manual ? $q->where('journal_type', 'MANUAL') : $q->where(fn ($w) => $w->whereNull('journal_type')->orWhere('journal_type', '!=', 'MANUAL'));

        return $q->orderByDesc('created_at')->limit(300)->get()
            ->mapWithKeys(fn ($j) => [$j->id => "{$j->reference_type} · ".($j->reason_code ?? $j->correlation_id).' · '.$j->currency.' · '.$j->status.' · '.substr((string) $j->created_at, 0, 10)])->all();
    }

    /** @return array<string, string> active accounts of the tenant chart (and the platform chart) */
    private static function ledgerAccounts(): array
    {
        return DB::table('ledger_accounts')->where(fn ($q) => $q->where('tenant_id', FinanceOperationsActions::tenant())->orWhereNull('tenant_id'))->where('status', 'ACTIVE')
            ->orderBy('code')->limit(1000)->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->code} — {$a->name} ({$a->currency})"])->all();
    }

    /** @param list<string> $statuses @return array<string, string> */
    private static function remittances(array $statuses): array
    {
        return DB::table('premium_remittances')->where('tenant_id', FinanceOperationsActions::tenant())->whereIn('status', $statuses)->orderByDesc('created_at')->limit(300)->get()
            ->mapWithKeys(fn ($r) => [$r->id => "{$r->remittance_number} · ".number_format((int) $r->amount_minor - (int) $r->allocated_minor, 0, ',', ' ')." {$r->currency} · {$r->status}"])->all();
    }

    /** @return array<string, string> */
    private static function imports(): array
    {
        return DB::table('technical_actuarial_imports')->where('tenant_id', FinanceOperationsActions::tenant())->where('status', 'PENDING_APPROVAL')->orderByDesc('period_end')->get()
            ->mapWithKeys(fn ($i) => [$i->id => "{$i->kind} · {$i->period_end} · v{$i->version} · {$i->source}"])->all();
    }
}
