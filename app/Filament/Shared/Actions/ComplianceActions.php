<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Compliance\Cases\ComplianceCaseService;
use App\Application\Compliance\Governance\GovernanceRegisterService;
use App\Application\Compliance\RegulatoryReportingService;
use App\Application\Privacy\DataSubjectRequestService;
use App\Application\Security\PrivilegedAccessService;
use App\Application\Trust\FraudReviewService;
use App\Domain\Tenancy\TenantContext;
use App\Models\ComplianceCase;
use App\Models\FraudRuleVersion;
use App\Models\Party;
use App\Models\RegulatoryReportDefinition;
use App\Models\RegulatoryReportRun;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Compliance and trust desktop actions. Each calls the SAME service method, with the SAME validation rules and
 * permission, as the API route it mirrors:
 *   caseOpen           POST compliance/cases                                compliance.cases.create        ComplianceCaseService::open
 *   caseLinkEvidence   POST compliance/cases/{case}/evidence                compliance.evidence.link       ComplianceCaseService::linkEvidence
 *   caseAddFinding     POST compliance/cases/{case}/findings                compliance.findings.manage     ComplianceCaseService::addFinding
 *   findingWithdraw    POST compliance/findings/{finding}/withdraw          compliance.findings.manage     ComplianceCaseService::withdrawFinding
 *   findingPlanAction  POST compliance/findings/{finding}/corrective-actions compliance.actions.manage     ComplianceCaseService::planAction
 *   actionEvent        POST compliance/corrective-actions/{action}/events   compliance.actions.manage      ComplianceCaseService::actOnAction
 *   actionVerify       POST compliance/corrective-actions/{action}/verify   compliance.actions.verify      ComplianceCaseService::actOnAction(verify)
 *   dsrReceive         POST compliance/data-subject-requests                compliance.dsr.receive         DataSubjectRequestService::receive
 *   accessRequest      POST compliance/privileged-access (scoped request)   compliance.access.grant        PrivilegedAccessService::request
 *   fraudAlert         POST trust/fraud-alerts                              trust.fraud-alerts.create      FraudReviewService::alert / manualAlert
 *   reportPrepare      POST trust/regulatory-reports/{d}/runs               trust.regulatory-reports.prepare     RegulatoryReportingService::prepare
 *   reportAcknowledge  POST trust/regulatory-report-runs/{x}/acknowledge    trust.regulatory-reports.acknowledge RegulatoryReportingService::acknowledge
 *   reportFail         POST trust/regulatory-report-runs/{x}/failure        trust.regulatory-reports.submit      RegulatoryReportingService::fail
 *   governanceCreate   POST compliance/governance/{register}                compliance.governance.manage   GovernanceRegisterService::create
 *   governanceUpdate   PATCH compliance/governance/{register}/{id}          compliance.governance.manage   GovernanceRegisterService::update
 *   exitPlanApprove    POST compliance/governance/exit-plans/{id}/approve   compliance.governance.approve  GovernanceRegisterService::approveExitPlan
 */
final class ComplianceActions
{
    private const L = 'compliance_actions';

    private const SEVERITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    public static function caseGroup(): ActionGroup
    {
        return ActionGroup::make([self::caseAddFinding(), self::findingPlanAction(), self::actionEvent(), self::actionVerify(), self::findingWithdraw(), self::caseLinkEvidence()])
            ->label(__(self::L.'.case_group'))->icon('lucide-zap')->button();
    }

    public static function caseOpen(): Action
    {
        $p = 'compliance.cases.create';

        return WorkflowAction::make('caseOpen', $p, self::L)->icon('lucide-folder-plus')
            ->schema([
                TextInput::make('type')->label(self::f('type'))->required()->maxLength(48),
                TextInput::make('subject_type')->label(self::f('subject_type'))->required()->maxLength(64),
                TextInput::make('subject_id')->label(self::f('subject_id'))->required()->uuid(),
                Select::make('severity')->label(self::f('severity'))->options(self::opts('severity', self::SEVERITIES))->required(),
                self::member('owner_id'),
                DatePicker::make('review_due_on')->label(self::f('review_due_on')),
                self::idempotency(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $d = self::check($data, ['type' => 'required|string|max:48', 'subject_type' => 'required|string|max:64', 'subject_id' => 'required|uuid',
                    'severity' => 'required|in:LOW,MEDIUM,HIGH,CRITICAL', 'owner_id' => 'nullable|uuid', 'review_due_on' => 'nullable|date', 'findings' => 'array',
                    'idempotency_key' => 'required|string|max:100']);

                return app(ComplianceCaseService::class)->open(self::tenant(), $d, self::user());
            }, __(self::L.'.caseOpen.done')));
    }

    public static function caseLinkEvidence(): Action
    {
        $p = 'compliance.evidence.link';

        return WorkflowAction::make('caseLinkEvidence', $p, self::L)->icon('lucide-paperclip')
            ->schema([
                Textarea::make('description')->label(self::f('description'))->required()->maxLength(1000),
                TextInput::make('document_id')->label(self::f('document_id'))->uuid(),
                TextInput::make('external_reference')->label(self::f('external_reference'))->maxLength(255),
                Select::make('finding_id')->label(self::f('finding_id'))->options(fn (ComplianceCase $record) => self::findings($record)),
                Select::make('corrective_action_id')->label(self::f('corrective_action_id'))->options(fn (ComplianceCase $record) => self::correctiveActions($record)),
            ])
            ->action(fn (Action $action, ComplianceCase $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = self::check($data, ['description' => 'required|string|max:1000', 'document_id' => 'nullable|uuid', 'external_reference' => 'nullable|string|max:255',
                    'finding_id' => 'nullable|uuid', 'corrective_action_id' => 'nullable|uuid']);

                return app(ComplianceCaseService::class)->linkEvidence(self::case($record), $d, self::user());
            }, __(self::L.'.caseLinkEvidence.done')));
    }

    public static function caseAddFinding(): Action
    {
        $p = 'compliance.findings.manage';

        return WorkflowAction::make('caseAddFinding', $p, self::L)->icon('lucide-search-check')
            ->visible(fn (ComplianceCase $record) => $record->status !== 'CLOSED')
            ->schema([
                TextInput::make('title')->label(self::f('title'))->required()->maxLength(255),
                Textarea::make('description')->label(self::f('description'))->maxLength(10000),
                Select::make('severity')->label(self::f('severity'))->options(self::opts('severity', self::SEVERITIES))->required(),
                TextInput::make('category')->label(self::f('category'))->maxLength(64),
            ])
            ->action(fn (Action $action, ComplianceCase $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = self::check($data, ['title' => 'required|string|max:255', 'description' => 'nullable|string|max:10000', 'severity' => 'required|in:LOW,MEDIUM,HIGH,CRITICAL', 'category' => 'nullable|string|max:64']);

                return app(ComplianceCaseService::class)->addFinding(self::case($record), $d, self::user());
            }, __(self::L.'.caseAddFinding.done')));
    }

    public static function findingWithdraw(): Action
    {
        $p = 'compliance.findings.manage';

        return WorkflowAction::make('findingWithdraw', $p, self::L)->icon('lucide-undo-2')->color('danger')
            ->visible(fn (ComplianceCase $record) => self::findings($record, ['OPEN', 'REMEDIATING']) !== [])
            ->schema([
                Select::make('finding_id')->label(self::f('finding_id'))->options(fn (ComplianceCase $record) => self::findings($record, ['OPEN', 'REMEDIATING']))->required(),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(2000),
            ])
            ->action(fn (Action $action, ComplianceCase $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = self::check($data, ['finding_id' => 'required|uuid', 'reason' => 'required|string|max:2000']);

                return app(ComplianceCaseService::class)->withdrawFinding(self::ownFinding($record, $d['finding_id']), self::tenant(), $d['reason'], self::user());
            }, __(self::L.'.findingWithdraw.done')));
    }

    public static function findingPlanAction(): Action
    {
        $p = 'compliance.actions.manage';

        return WorkflowAction::make('findingPlanAction', $p, self::L)->icon('lucide-list-todo')
            ->visible(fn (ComplianceCase $record) => self::findings($record, ['OPEN', 'REMEDIATING']) !== [])
            ->schema([
                Select::make('finding_id')->label(self::f('finding_id'))->options(fn (ComplianceCase $record) => self::findings($record, ['OPEN', 'REMEDIATING']))->required(),
                Textarea::make('description')->label(self::f('description'))->required()->maxLength(4000),
                self::member('owner_user_id')->required(),
                DatePicker::make('due_on')->label(self::f('due_on'))->required()->minDate(today()),
            ])
            ->action(fn (Action $action, ComplianceCase $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = self::check($data, ['finding_id' => 'required|uuid', 'description' => 'required|string|max:4000', 'owner_user_id' => 'required|uuid', 'due_on' => 'required|date|after_or_equal:today']);
                $finding = self::ownFinding($record, $d['finding_id']);
                unset($d['finding_id']);

                return app(ComplianceCaseService::class)->planAction($finding, self::tenant(), $d, self::user());
            }, __(self::L.'.findingPlanAction.done')));
    }

    public static function actionEvent(): Action
    {
        $p = 'compliance.actions.manage';
        $live = ['PLANNED', 'IN_PROGRESS', 'COMPLETED'];

        return WorkflowAction::make('actionEvent', $p, self::L)->icon('lucide-play')
            ->visible(fn (ComplianceCase $record) => self::correctiveActions($record, $live) !== [])
            ->schema([
                Select::make('corrective_action_id')->label(self::f('corrective_action_id'))->options(fn (ComplianceCase $record) => self::correctiveActions($record, $live))->required(),
                Select::make('event')->label(self::f('event'))->options(self::opts('event', ['start', 'complete', 'cancel']))->required(),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(4000),
            ])
            ->action(fn (Action $action, ComplianceCase $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = self::check($data, ['corrective_action_id' => 'required|uuid', 'event' => 'required|in:start,complete,cancel', 'notes' => 'nullable|string|max:4000']);

                return app(ComplianceCaseService::class)->actOnAction(self::ownAction($record, $d['corrective_action_id']), self::tenant(), $d['event'], $d, self::user());
            }, __(self::L.'.actionEvent.done')));
    }

    public static function actionVerify(): Action
    {
        $p = 'compliance.actions.verify';

        return WorkflowAction::make('actionVerify', $p, self::L)->icon('lucide-badge-check')->color('success')
            ->visible(fn (ComplianceCase $record) => self::correctiveActions($record, ['COMPLETED']) !== [])
            ->schema([
                Select::make('corrective_action_id')->label(self::f('corrective_action_id'))->options(fn (ComplianceCase $record) => self::correctiveActions($record, ['COMPLETED']))->required(),
                Toggle::make('accepted')->label(self::f('accepted'))->default(true),
                Textarea::make('notes')->label(self::f('notes'))->required()->maxLength(4000),
            ])
            ->action(fn (Action $action, ComplianceCase $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = self::check($data, ['corrective_action_id' => 'required|uuid', 'accepted' => 'required|boolean', 'notes' => 'required|string|max:4000']);

                return app(ComplianceCaseService::class)->actOnAction(self::ownAction($record, $d['corrective_action_id']), self::tenant(), 'verify', $d, self::user());
            }, __(self::L.'.actionVerify.done')));
    }

    public static function dsrReceive(): Action
    {
        $p = 'compliance.dsr.receive';
        $types = ['ACCESS', 'CORRECTION', 'ERASURE', 'RESTRICTION', 'PORTABILITY', 'OBJECTION'];

        return WorkflowAction::make('dsrReceive', $p, self::L)->icon('lucide-inbox')
            ->schema([
                Select::make('party_id')->label(self::f('party_id'))->searchable()->required()
                    ->getSearchResultsUsing(fn (string $search) => Party::whereIn('id', DB::table('tenant_customers')->where('tenant_id', self::tenant())->select('party_id'))
                        ->where('display_name', 'ilike', "%{$search}%")->limit(20)->pluck('display_name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => Party::find($value)?->display_name),
                Select::make('type')->label(self::f('type'))->options(self::opts('dsr_type', $types))->required(),
                DatePicker::make('due_on')->label(self::f('due_on')),
                self::member('assigned_to'),
                self::idempotency(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $d = self::check($data, ['party_id' => 'required|uuid|exists:parties,id', 'type' => 'required|in:ACCESS,CORRECTION,DELETION,ERASURE,RESTRICTION,PORTABILITY,OBJECTION',
                    'due_on' => 'nullable|date', 'assigned_to' => 'nullable|uuid', 'idempotency_key' => 'nullable|string|max:100']);

                return app(DataSubjectRequestService::class)->receive(self::tenant(), array_filter($d, fn ($v) => $v !== null), self::user());
            }, __(self::L.'.dsrReceive.done')));
    }

    public static function accessRequest(): Action
    {
        $p = 'compliance.access.grant';

        return WorkflowAction::make('accessRequest', $p, self::L)->icon('lucide-key-round')
            ->schema([
                self::member('user_id')->required(),
                TextInput::make('purpose')->label(self::f('purpose'))->required()->maxLength(64),
                Textarea::make('justification')->label(self::f('justification'))->required()->minLength(20)->maxLength(2000),
                DateTimePicker::make('starts_at')->label(self::f('starts_at'))->required(),
                DateTimePicker::make('expires_at')->label(self::f('expires_at'))->required(),
                TagsInput::make('scope')->label(self::f('scope'))->required(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $d = self::check($data, ['user_id' => 'required|uuid', 'purpose' => 'required|string|max:64', 'justification' => 'required|string|min:20|max:2000',
                    'starts_at' => 'required|date', 'expires_at' => 'required|date', 'scope' => 'required|array|min:1']);

                return app(PrivilegedAccessService::class)->request(self::tenant(), User::findOrFail($d['user_id']), $d, self::user());
            }, __(self::L.'.accessRequest.done')));
    }

    public static function fraudAlert(): Action
    {
        $p = 'trust.fraud-alerts.create';
        $subjects = ['PAYMENT', 'QUOTE', 'POLICY', 'CLAIM', 'COMMISSION', 'USER'];

        return WorkflowAction::make('fraudAlert', $p, self::L)->icon('lucide-shield-alert')->color('danger')
            ->schema([
                Select::make('subject_type')->label(self::f('subject_type'))->options(self::opts('subject_type', $subjects))->required(),
                TextInput::make('subject_id')->label(self::f('subject_id'))->required()->uuid(),
                TextInput::make('alert_type')->label(self::f('alert_type'))->required()->maxLength(64),
                Select::make('fraud_rule_version_id')->label(self::f('fraud_rule_version_id'))->live()
                    ->options(fn () => FraudRuleVersion::where('status', 'ACTIVE')->orderBy('code')->get()->mapWithKeys(fn ($r) => [$r->id => "{$r->code} v{$r->version}"])->all()),
                Select::make('severity')->label(self::f('severity'))->options(self::opts('severity', self::SEVERITIES))
                    ->visible(fn ($get) => filled($get('fraud_rule_version_id')))->required(fn ($get) => filled($get('fraud_rule_version_id'))),
                TextInput::make('risk_score')->label(self::f('risk_score'))->integer()->minValue(0)->maxValue(100)
                    ->visible(fn ($get) => blank($get('fraud_rule_version_id')))->required(fn ($get) => blank($get('fraud_rule_version_id'))),
                KeyValue::make('signals')->label(self::f('signals'))->keyLabel(self::f('key'))->valueLabel(self::f('value'))->required(),
                self::idempotency(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $s = app(FraudReviewService::class);
                if (filled($data['fraud_rule_version_id'] ?? null)) {
                    $d = self::check($data, ['subject_type' => 'required|string|max:64', 'subject_id' => 'required|uuid', 'alert_type' => 'required|string|max:64',
                        'severity' => 'required|in:LOW,MEDIUM,HIGH,CRITICAL', 'signals' => 'required|array', 'fraud_rule_version_id' => 'required|uuid',
                        'idempotency_key' => 'required|string|max:100', 'review_due_at' => 'nullable|date']);

                    return $s->alert(self::tenant(), $d, self::user());
                }
                $d = self::check($data, ['subject_type' => 'required|in:PAYMENT,QUOTE,POLICY,CLAIM,COMMISSION,USER', 'subject_id' => 'required|uuid',
                    'alert_type' => 'required|string|max:64', 'risk_score' => 'required|integer|min:0|max:100', 'signals' => 'required|array|min:1']);

                return $s->manualAlert(self::tenant(), $d, self::user());
            }, __(self::L.'.fraudAlert.done')));
    }

    public static function reportPrepare(): Action
    {
        $p = 'trust.regulatory-reports.prepare';

        return WorkflowAction::make('reportPrepare', $p, self::L)->icon('lucide-file-plus')
            ->schema([
                Select::make('definition_id')->label(self::f('definition_id'))->required()
                    ->options(fn () => RegulatoryReportDefinition::where('status', 'ACTIVE')->orderBy('code')->get()->mapWithKeys(fn ($d) => [$d->id => "{$d->code} v{$d->version}"])->all()),
                TextInput::make('period_key')->label(self::f('period_key'))->required()->maxLength(40),
                KeyValue::make('payload')->label(self::f('payload'))->keyLabel(self::f('key'))->valueLabel(self::f('value'))->required(),
                self::idempotency(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $definition = RegulatoryReportDefinition::findOrFail($data['definition_id'] ?? null);
                $in = self::check($data, ['period_key' => 'required|string|max:40', 'payload' => 'required|array', 'idempotency_key' => 'required|string|max:100']);

                return app(RegulatoryReportingService::class)->prepare(self::tenant(), $definition, $in, self::user());
            }, __(self::L.'.reportPrepare.done')));
    }

    public static function reportAcknowledge(): Action
    {
        $p = 'trust.regulatory-reports.acknowledge';

        return WorkflowAction::make('reportAcknowledge', $p, self::L)->icon('lucide-mail-check')->color('success')
            ->visible(fn (RegulatoryReportRun $record) => $record->status === 'SUBMITTING')
            ->schema([TextInput::make('external_reference')->label(self::f('external_reference'))->required()->maxLength(255)])
            ->action(fn (Action $action, RegulatoryReportRun $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = self::check($data, ['external_reference' => 'required|string|max:255']);

                return app(RegulatoryReportingService::class)->acknowledge(self::run($record), $d['external_reference']);
            }, __(self::L.'.reportAcknowledge.done')));
    }

    public static function reportFail(): Action
    {
        $p = 'trust.regulatory-reports.submit';

        return WorkflowAction::make('reportFail', $p, self::L)->icon('lucide-circle-alert')->color('danger')
            ->visible(fn (RegulatoryReportRun $record) => $record->status === 'SUBMITTING')
            ->schema([Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(2000)])
            ->action(fn (Action $action, RegulatoryReportRun $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = self::check($data, ['reason' => 'required|string|max:2000']);

                return app(RegulatoryReportingService::class)->fail(self::run($record), $d['reason']);
            }, __(self::L.'.reportFail.done')));
    }

    /** Header action of the governance registers page; $register resolves the register shown. */
    public static function governanceCreate(\Closure $register): Action
    {
        $p = 'compliance.governance.manage';

        return WorkflowAction::make('governanceCreate', $p, self::L)->icon('lucide-plus')
            ->schema(fn () => self::governanceFields($register(), false))
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(GovernanceRegisterService::class)->create($register(), self::tenant(), self::present($data), self::user()), __(self::L.'.governanceCreate.done')));
    }

    public static function governanceUpdate(\Closure $register): Action
    {
        $p = 'compliance.governance.manage';

        return WorkflowAction::make('governanceUpdate', $p, self::L)->icon('lucide-pencil')
            ->schema(fn () => self::governanceFields($register(), true))
            ->fillForm(fn ($record) => collect($record->getAttributes())->only(array_keys(self::GOVERNANCE_FIELDS[$register()] ?? []))->all())
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(GovernanceRegisterService::class)->update($register(), self::tenant(), (string) $record->getKey(), self::present($data), self::user()), __(self::L.'.governanceUpdate.done')));
    }

    public static function exitPlanApprove(\Closure $register): Action
    {
        $p = 'compliance.governance.approve';

        return WorkflowAction::make('exitPlanApprove', $p, self::L)->icon('lucide-badge-check')->color('success')->requiresConfirmation()
            ->visible(fn ($record) => $register() === 'exit-plans' && ($record->status ?? null) === 'DRAFT')
            ->action(fn (Action $action, $record) => WorkflowAction::run($action, $p,
                fn () => app(GovernanceRegisterService::class)->approveExitPlan(self::tenant(), (string) $record->getKey(), self::user()), __(self::L.'.exitPlanApprove.done')));
    }

    /**
     * Register form fields (mirror of GovernanceRegisterService::rules; the service validates).
     * kind: text|textarea|date|datetime|rating|bool|member|in:A,B|ref:table:label
     */
    public const GOVERNANCE_FIELDS = [
        'ict-assets' => ['asset_code' => ['text', true], 'name' => ['text', true], 'category' => ['text', true], 'criticality' => ['rating', false],
            'owner_user_id' => ['member', false], 'vendor_id' => ['ref:governance_vendors:name', false], 'status' => ['in:ACTIVE,RETIRED', false]],
        'ict-incidents' => ['title' => ['text', true], 'ict_asset_id' => ['ref:governance_ict_assets:name', false], 'description' => ['textarea', false],
            'severity' => ['rating', true], 'status' => ['in:OPEN,CONTAINED,RESOLVED,CLOSED', false], 'detected_at' => ['datetime', true], 'resolved_at' => ['datetime', false],
            'reported_externally_at' => ['datetime', false], 'external_reference' => ['text', false], 'compliance_case_id' => ['ref:compliance_cases:case_number', false]],
        'vendors' => ['vendor_code' => ['text', true], 'name' => ['text', true], 'services' => ['textarea', false], 'is_outsourcing' => ['bool', false],
            'criticality' => ['rating', false], 'risk_rating' => ['rating', false], 'status' => ['in:ACTIVE,UNDER_REVIEW,EXITING,EXITED', false]],
        'outsourcing-contracts' => ['vendor_id' => ['ref:governance_vendors:name', true], 'contract_reference' => ['text', true], 'service_description' => ['textarea', true],
            'start_on' => ['date', true], 'end_on' => ['date', false], 'risk_rating' => ['rating', false], 'status' => ['in:DRAFT,ACTIVE,TERMINATING,TERMINATED', false]],
        'due-diligence-reviews' => ['vendor_id' => ['ref:governance_vendors:name', true], 'contract_id' => ['ref:governance_outsourcing_contracts:contract_reference', false],
            'review_date' => ['date', true], 'outcome' => ['in:SATISFACTORY,CONDITIONAL,UNSATISFACTORY', true], 'risk_rating' => ['rating', false],
            'next_review_on' => ['date', false], 'notes' => ['textarea', false]],
        'exit-plans' => ['contract_id' => ['ref:governance_outsourcing_contracts:contract_reference', true], 'summary' => ['textarea', true], 'last_tested_on' => ['date', false]],
    ];

    /** @return list<\Filament\Forms\Components\Field> */
    public static function governanceFields(string $register, bool $update): array
    {
        $out = [];
        foreach (self::GOVERNANCE_FIELDS[$register] ?? [] as $name => [$kind, $required]) {
            $c = match (true) {
                $kind === 'textarea' => Textarea::make($name)->maxLength(20000),
                $kind === 'date' => DatePicker::make($name),
                $kind === 'datetime' => DateTimePicker::make($name),
                $kind === 'bool' => Toggle::make($name),
                $kind === 'rating' => Select::make($name)->options(self::opts('severity', GovernanceRegisterService::RATINGS)),
                $kind === 'member' => self::member($name),
                str_starts_with($kind, 'in:') => Select::make($name)->options(self::opts($name === 'outcome' ? 'outcome' : null, explode(',', substr($kind, 3)))),
                str_starts_with($kind, 'ref:') => self::ref($name, ...explode(':', substr($kind, 4))),
                default => TextInput::make($name)->maxLength(255),
            };
            $out[] = $c->label(self::f($name))->required($required && ! $update && $kind !== 'bool');
        }

        return $out;
    }

    private static function ref(string $name, string $table, string $label): Select
    {
        return Select::make($name)->searchable()
            ->options(fn () => DB::table($table)->where('tenant_id', self::tenant())->orderBy($label)->limit(200)->pluck($label, 'id')->all());
    }

    private static function member(string $name): Select
    {
        return Select::make($name)->label(self::f($name))->searchable()
            ->getSearchResultsUsing(fn (string $search) => User::whereHas('memberships', fn ($m) => $m->where('tenant_id', self::tenant())->where('status', 'ACTIVE'))
                ->where('full_name', 'ilike', "%{$search}%")->limit(20)->pluck('full_name', 'id')->all())
            ->getOptionLabelUsing(fn ($value) => User::find($value)?->full_name);
    }

    private static function idempotency(): Hidden
    {
        return Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid());
    }

    /** @param  list<string>|null  $statuses */
    private static function findings(ComplianceCase $case, ?array $statuses = null): array
    {
        return DB::table('compliance_findings')->where('compliance_case_id', $case->id)
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))->orderBy('created_at')->pluck('title', 'id')->all();
    }

    /** @param  list<string>|null  $statuses */
    private static function correctiveActions(ComplianceCase $case, ?array $statuses = null): array
    {
        return DB::table('compliance_corrective_actions')->where('compliance_case_id', $case->id)
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))->orderBy('due_on')->get()
            ->mapWithKeys(fn ($a) => [$a->id => Str::limit($a->description, 80).' ('.$a->status.')'])->all();
    }

    /** A finding / action id chosen in the form must belong to the case on screen. */
    private static function ownFinding(ComplianceCase $case, string $id): string
    {
        DB::table('compliance_findings')->where('id', $id)->where('compliance_case_id', $case->id)->exists() || abort(404);

        return $id;
    }

    private static function ownAction(ComplianceCase $case, string $id): string
    {
        DB::table('compliance_corrective_actions')->where('id', $id)->where('compliance_case_id', $case->id)->exists() || abort(404);

        return $id;
    }

    private static function case(ComplianceCase $record): ComplianceCase
    {
        return ComplianceCase::where('tenant_id', self::tenant())->findOrFail($record->id);
    }

    private static function run(RegulatoryReportRun $record): RegulatoryReportRun
    {
        return RegulatoryReportRun::where('tenant_id', self::tenant())->findOrFail($record->id);
    }

    /** Same rules as the API controller; a ValidationException is shown by WorkflowAction::run. */
    private static function check(array $data, array $rules): array
    {
        return Validator::make(self::present($data), $rules)->validate();
    }

    /** Blank form fields are "not sent", as in the API. */
    private static function present(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private static function opts(?string $group, array $values): array
    {
        return collect($values)->mapWithKeys(fn ($v) => [$v => $group ? (WorkflowAction::optional(self::L.".options.{$group}.{$v}") ?? $v) : $v])->all();
    }

    private static function f(string $name): string
    {
        return __(self::L.'.fields.'.$name);
    }

    private static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private static function user(): User
    {
        return auth()->user();
    }
}
