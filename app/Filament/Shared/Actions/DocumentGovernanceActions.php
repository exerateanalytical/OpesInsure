<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Documents\DocumentOrigin;
use App\Application\Documents\Intake\DocumentIntakeService;
use App\Application\Documents\Retention\DocumentDestructionService;
use App\Application\Documents\Retention\LegalHoldService;
use App\Application\Documents\Retention\RetentionScheduleService;
use App\Application\Documents\Signatures\SignatureService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/**
 * Document governance workflow (batch 21, REQ-DOC-009/010/012). Same permission + service + validation as
 * App\Application\Documents\Http\DocumentGovernanceController (routes/api.php v1/document-governance):
 *   dgIntakeReceive      POST intake                                   documents.intake.manage       DocumentIntakeService::receive
 *   dgIntakeClassify     POST intake/{item}/classify                   documents.intake.manage       DocumentIntakeService::classify
 *   dgIntakeReject       POST intake/{item}/reject                     documents.intake.manage       DocumentIntakeService::reject
 *   dgRetentionDraft     POST retention-schedules                      documents.retention.manage    RetentionScheduleService::draft
 *   dgRetentionApprove   POST retention-schedules/{s}/approve          documents.retention.approve   RetentionScheduleService::approve
 *   dgHoldPlace          POST legal-holds                              documents.legal_hold.manage   LegalHoldService::place
 *   dgHoldRelease        POST legal-holds/{hold}/release               documents.legal_hold.manage   LegalHoldService::release
 *   dgDestructionRequest POST documents/{document}/destruction-requests documents.destruction.request DocumentDestructionService::request
 *   dgDestructionDecide  POST destruction-requests/{r}/decide          documents.destruction.approve DocumentDestructionService::decide
 *   dgSignatureRequest   POST signature-requests                       documents.signatures.manage   SignatureService::request
 *   dgSignatureCancel    POST signature-requests/{r}/cancel            documents.signatures.manage   SignatureService::cancel
 * Maker-checker (schedule approver ≠ drafter, destruction decider ≠ requester) is enforced by the services.
 */
final class DocumentGovernanceActions
{
    private const LANG = 'masterdata_actions';

    public static function intakeReceive(): Action
    {
        $p = 'documents.intake.manage';

        return WorkflowAction::make('dgIntakeReceive', $p, self::LANG)->icon('lucide-inbox')
            ->schema([
                self::documentSelect(),
                Select::make('channel')->label(self::f('channel'))->required()->options(WorkflowAction::options(['UPLOAD', 'EMAIL', 'POST', 'PORTAL', 'API', 'SCAN'])),
                TextInput::make('declared_type_code')->label(self::f('declared_type_code'))->maxLength(96),
                TextInput::make('original_filename')->label(self::f('original_filename'))->maxLength(255),
                TextInput::make('origin')->label(self::f('origin'))->maxLength(16),
                Select::make('stage')->label(self::f('stage'))->options(WorkflowAction::options(DocumentOrigin::STAGES)),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(DocumentIntakeService::class)->receive(self::tenant(), self::clean($data), auth()->user()), __('masterdata_actions.dgIntakeReceive.done')));
    }

    public static function intakeClassify(): Action
    {
        $p = 'documents.intake.manage';

        return WorkflowAction::make('dgIntakeClassify', $p, self::LANG)->icon('lucide-tags')
            ->visible(fn (array $record) => in_array($record['status'], ['RECEIVED', 'EXCEPTION'], true))
            ->fillForm(fn (array $record) => ['document_type_code' => $record['suggested_type_code'] ?? $record['declared_type_code'] ?? null])
            ->schema([TextInput::make('document_type_code')->label(self::f('document_type_code'))->required()->maxLength(96)])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(DocumentIntakeService::class)->classify(self::tenant(), (string) $record['id'], $data['document_type_code'], auth()->user()), __('masterdata_actions.dgIntakeClassify.done')));
    }

    public static function intakeReject(): Action
    {
        $p = 'documents.intake.manage';

        return WorkflowAction::make('dgIntakeReject', $p, self::LANG)->icon('lucide-x-circle')->color('danger')
            ->visible(fn (array $record) => in_array($record['status'], ['RECEIVED', 'EXCEPTION'], true))
            ->schema([self::reason()])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(DocumentIntakeService::class)->reject(self::tenant(), (string) $record['id'], $data['reason'], auth()->user()), __('masterdata_actions.dgIntakeReject.done')));
    }

    public static function retentionDraft(): Action
    {
        $p = 'documents.retention.manage';

        return WorkflowAction::make('dgRetentionDraft', $p, self::LANG)->icon('lucide-plus')
            ->schema([
                TextInput::make('code')->label(self::f('code'))->required()->maxLength(64),
                TextInput::make('document_type_code')->label(self::f('document_type_code'))->maxLength(96),
                TextInput::make('document_group')->label(self::f('document_group'))->maxLength(40),
                TextInput::make('security_level')->label(self::f('security_level'))->maxLength(32),
                TextInput::make('retention_years')->label(self::f('retention_years'))->required()->integer()->minValue(1)->maxValue(200),
                Select::make('trigger_event')->label(self::f('trigger_event'))->options(WorkflowAction::options(['ISSUED_AT', 'CREATED_AT', 'VALID_UNTIL'])),
                Select::make('disposition')->label(self::f('disposition'))->options(WorkflowAction::options(['DESTROY', 'REVIEW'])),
                Textarea::make('legal_basis')->label(self::f('legal_basis'))->required()->maxLength(2000),
                TextInput::make('retention_class')->label(self::f('retention_class'))->maxLength(32),
                Toggle::make('legal_hold_override')->label(self::f('legal_hold_override')),
                TextInput::make('destruction_method')->label(self::f('destruction_method'))->maxLength(40),
                DatePicker::make('effective_from')->label(self::f('effective_from')),
                DatePicker::make('effective_until')->label(self::f('effective_until'))->afterOrEqual('effective_from'),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $data['retention_years'] = (int) $data['retention_years'];
                if (empty($data['legal_hold_override'])) {
                    unset($data['legal_hold_override']);
                }

                return WorkflowAction::run($action, $p, fn () => app(RetentionScheduleService::class)->draft(self::tenant(), self::clean($data), auth()->user()), __('masterdata_actions.dgRetentionDraft.done'));
            });
    }

    public static function retentionApprove(): Action
    {
        $p = 'documents.retention.approve';

        return WorkflowAction::make('dgRetentionApprove', $p, self::LANG)->icon('lucide-check-check')->color('success')->requiresConfirmation()
            ->visible(fn (array $record) => $record['status'] === 'DRAFT')
            ->action(fn (Action $action, array $record) => WorkflowAction::run($action, $p,
                fn () => app(RetentionScheduleService::class)->approve(self::tenant(), (string) $record['id'], auth()->user()), __('masterdata_actions.dgRetentionApprove.done')));
    }

    public static function holdPlace(): Action
    {
        $p = 'documents.legal_hold.manage';

        return WorkflowAction::make('dgHoldPlace', $p, self::LANG)->icon('lucide-lock')
            ->schema([
                Select::make('subject_type')->label(self::f('subject_type'))->required()->options(WorkflowAction::options(LegalHoldService::SUBJECT_TYPES)),
                TextInput::make('subject_id')->label(self::f('subject_id'))->required()->uuid(),
                TextInput::make('reason_code')->label(self::f('reason_code'))->required()->maxLength(64),
                Textarea::make('notes')->label(self::f('notes'))->required()->maxLength(5000),
                TextInput::make('case_id')->label(self::f('case_id'))->uuid(),
                DatePicker::make('hold_until')->label(self::f('hold_until')),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(LegalHoldService::class)->place(self::tenant(), self::clean($data), auth()->user()), __('masterdata_actions.dgHoldPlace.done')));
    }

    public static function holdRelease(): Action
    {
        $p = 'documents.legal_hold.manage';

        return WorkflowAction::make('dgHoldRelease', $p, self::LANG)->icon('lucide-lock-open')->color('warning')
            ->visible(fn (array $record) => empty($record['released_at']))
            ->schema([self::reason()])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(LegalHoldService::class)->release(self::tenant(), (string) $record['id'], $data['reason'], auth()->user()), __('masterdata_actions.dgHoldRelease.done')));
    }

    public static function destructionRequest(): Action
    {
        $p = 'documents.destruction.request';

        return WorkflowAction::make('dgDestructionRequest', $p, self::LANG)->icon('lucide-trash')->color('danger')
            ->schema([self::documentSelect(), self::reason()])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(DocumentDestructionService::class)->request(self::tenant(), $data['document_id'], $data['reason'], auth()->user()), __('masterdata_actions.dgDestructionRequest.done')));
    }

    public static function destructionDecide(): Action
    {
        $p = 'documents.destruction.approve';

        return WorkflowAction::make('dgDestructionDecide', $p, self::LANG)->icon('lucide-gavel')
            ->visible(fn (array $record) => $record['status'] === 'PENDING')
            ->schema([
                Toggle::make('approve')->label(self::f('approve'))->default(false),
                Textarea::make('note')->label(self::f('note'))->required()->maxLength(2000),
            ])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(DocumentDestructionService::class)->decide(self::tenant(), (string) $record['id'], (bool) ($data['approve'] ?? false), $data['note'], auth()->user()),
                __('masterdata_actions.dgDestructionDecide.done')));
    }

    public static function signatureRequest(): Action
    {
        $p = 'documents.signatures.manage';

        return WorkflowAction::make('dgSignatureRequest', $p, self::LANG)->icon('lucide-signature')
            ->schema([
                self::documentSelect(),
                Textarea::make('consent_text')->label(self::f('consent_text'))->required()->maxLength(5000),
                TextInput::make('provider')->label(self::f('provider'))->maxLength(32),
                DateTimePicker::make('expires_at')->label(self::f('expires_at'))->after('now'),
                Repeater::make('signers')->label(self::f('signers'))->required()->minItems(1)->maxItems(20)->schema([
                    TextInput::make('name')->label(self::f('signer_name'))->required()->maxLength(160),
                    TextInput::make('role')->label(self::f('signer_role'))->required()->maxLength(32),
                    TextInput::make('user_id')->label(self::f('signer_user_id'))->uuid(),
                    TextInput::make('party_id')->label(self::f('signer_party_id'))->uuid(),
                    TextInput::make('order')->label(self::f('signer_order'))->integer()->minValue(1)->maxValue(50),
                ]),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $data['signers'] = array_values(array_map(fn ($s) => array_map(fn ($v) => is_numeric($v) && ! str_contains((string) $v, '-') ? (int) $v : $v, self::clean($s)), $data['signers'] ?? []));

                return WorkflowAction::run($action, $p, fn () => app(SignatureService::class)->request(self::tenant(), self::clean($data), auth()->user()), __('masterdata_actions.dgSignatureRequest.done'));
            });
    }

    public static function signatureCancel(): Action
    {
        $p = 'documents.signatures.manage';

        return WorkflowAction::make('dgSignatureCancel', $p, self::LANG)->icon('lucide-ban')->color('danger')
            ->visible(fn (array $record) => $record['status'] === 'PENDING')
            ->schema([self::reason()])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SignatureService::class)->cancel(self::tenant(), (string) $record['id'], auth()->user(), $data['reason']), __('masterdata_actions.dgSignatureCancel.done')));
    }

    private static function documentSelect(): Select
    {
        return Select::make('document_id')->label(self::f('document'))->required()->searchable()
            ->getSearchResultsUsing(fn (string $search) => self::documents($search))
            ->getOptionLabelUsing(fn ($value) => self::documents(null, (string) $value)[$value] ?? $value)
            ->options(fn () => self::documents());
    }

    /** The tenant's documents (the services re-check tenant ownership). */
    private static function documents(?string $search = null, ?string $id = null): array
    {
        return Document::where('tenant_id', self::tenant())->when($id, fn ($q) => $q->whereKey($id))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('title', 'ilike', "%{$search}%")->orWhere('document_number', 'ilike', "%{$search}%")->orWhere('document_type_code', 'ilike', "%{$search}%")))
            ->latest('created_at')->limit(50)->get()
            ->mapWithKeys(fn (Document $d) => [$d->id => trim(($d->document_number ?: $d->title ?: $d->document_type_code ?: $d->category).' · '.$d->created_at?->format('Y-m-d'))])->all();
    }

    private static function reason(): Textarea
    {
        return Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(2000);
    }

    private static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private static function clean(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private static function f(string $k): string
    {
        return __("masterdata_actions.fields.{$k}");
    }
}
