<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Cases\Bridges\LegacyWorkItemBridge;
use App\Application\Cases\CaseService;
use App\Application\Cases\DiaryService;
use App\Application\Cases\Models\CaseTask;
use App\Application\Cases\Models\CaseType;
use App\Application\Cases\Models\WorkCase;
use App\Application\Complaints\ComplaintService;
use App\Application\Correspondence\CorrespondenceService;
use App\Application\OperationsTaxonomy\OperationsCatalogue;
use App\Application\Support\SupportTicketService;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\SupportTicket;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Support desk actions: cases, complaints and support tickets. Same permission + validation + service call as the API
 * (routes/cases.php, routes/wave8.php):
 *   caseLinkLegacy        POST admin/cases/links                        cases.admin   LegacyWorkItemBridge::link (complaint tickets: ComplaintService::fromSupportTicket)
 *   caseDecide            POST cases/{c}/decisions                      cases.decide  CaseService::decide
 *   caseDiary             POST cases/{c}/diary                          cases.manage  DiaryService::add
 *   caseReclassify        POST cases/{c}/subtype                        cases.manage  CaseService::reclassify
 *   caseAddTask           POST cases/{c}/tasks                          cases.manage  CaseService::addTask
 *   caseTaskTransition    POST cases/{c}/tasks/{t}/transitions          cases.manage  CaseService::transitionTask
 *   complaintSubmit       POST complaints                               cases.manage  ComplaintService::submit
 *   complaintFromTicket   POST complaints/from-ticket                   cases.manage  ComplaintService::fromSupportTicket
 *   complaintAcknowledge  POST complaints/{c}/acknowledge               cases.manage  ComplaintService::acknowledge
 *   complaintAssign       POST complaints/{c}/assign                    cases.assign  ComplaintService::assignInvestigator
 *   complaintClassify     POST complaints/{c}/classify                  cases.manage  ComplaintService::classify
 *   complaintCommunicate  POST complaints/{c}/communicate               cases.manage  ComplaintService::communicate
 *   complaintEscalate     POST complaints/{c}/escalate                  cases.manage  ComplaintService::escalate
 *   complaintInvestigate  POST complaints/{c}/investigate               cases.manage  ComplaintService::investigate
 *   complaintResolution   POST complaints/{c}/resolution                cases.decide  ComplaintService::proposeResolution
 *   complaintAdvance      POST complaints/{c}/transition                cases.manage  ComplaintService::advance
 *   ticketOpen            POST support/tickets                          support.manage SupportTicketService::open
 *   ticketTransition      POST support/tickets/{t}/transitions          support.manage SupportTicketService::transition (TicketStateMachine::assert)
 * Every lifecycle guard stays in the services (case state machine, complaint facts, ticket state machine); refusals
 * surface as a danger notification.
 */
final class SupportActions
{
    private const LANG = 'support_actions';

    private static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private static function done(string $name): string
    {
        return __(self::LANG.".{$name}.done");
    }

    private static function f(string $key): string
    {
        return __(self::LANG.".fields.{$key}");
    }

    /** @param array<int, string> $values */
    private static function opts(array $values): array
    {
        return array_combine($values, $values);
    }

    private static function isComplaint(WorkCase $record): bool
    {
        return $record->case_type_code === 'COMPLAINT' && $record->closed_at === null;
    }

    /** Re-reads the case the way ComplaintController::find does (tenant + COMPLAINT type). */
    private static function complaint(WorkCase $record): WorkCase
    {
        return WorkCase::query()->where('tenant_id', self::tenant())->where('case_type_code', 'COMPLAINT')->whereKey($record->id)->firstOrFail();
    }

    private static function case(WorkCase $record): WorkCase
    {
        return WorkCase::query()->where('tenant_id', self::tenant())->whereKey($record->id)->firstOrFail();
    }

    // ---------------------------------------------------------------- page groups

    /** @return list<Action> ListCaseRecords header. */
    public static function caseListHeader(): array
    {
        return [self::complaintSubmit(), self::linkLegacy()];
    }

    /** @return list<ActionGroup> ViewCaseRecord header. */
    public static function caseViewHeader(): array
    {
        return [
            ActionGroup::make([self::decide(), self::diary(), self::reclassify(), self::addTask(), self::taskTransition()])
                ->label(__('support_actions.case_group'))->icon('lucide-zap')->button(),
            ActionGroup::make([self::complaintAcknowledge(), self::complaintClassify(), self::complaintAssign(), self::complaintInvestigate(),
                self::complaintResolution(), self::complaintCommunicate(), self::complaintEscalate(), self::complaintAdvance()])
                ->label(__('support_actions.complaint_group'))->icon('lucide-message-square-warning')->button(),
        ];
    }

    /** @return list<Action> ViewSupportTicket header. */
    public static function ticketViewHeader(): array
    {
        return [self::ticketTransition(), self::complaintFromTicket()];
    }

    // ---------------------------------------------------------------- cases

    public static function linkLegacy(): Action
    {
        $p = 'cases.admin';

        return WorkflowAction::make('caseLinkLegacy', $p, self::LANG)->icon('lucide-link')
            ->schema([
                Select::make('source')->label(self::f('source'))->required()
                    ->options(collect(LegacyWorkItemBridge::SOURCES)->mapWithKeys(fn ($s) => [$s => __("support_actions.sources.{$s}")])->all()),
                TextInput::make('id')->label(self::f('source_id'))->required()->uuid(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                if ($data['source'] === 'support_tickets') {
                    // Same branch as CaseController::link: a complaint ticket is consolidated as a complaint (REQ-DUP-022).
                    return app(ComplaintService::class)->fromSupportTicket(self::tenant(), $data['id'], auth()->user());
                }

                return app(LegacyWorkItemBridge::class)->link($data['source'], $data['id'], self::tenant(), auth()->user());
            }, self::done('caseLinkLegacy')));
    }

    public static function decide(): Action
    {
        $p = 'cases.decide';

        return WorkflowAction::make('caseDecide', $p, self::LANG)->icon('lucide-gavel')
            ->visible(fn (WorkCase $record) => $record->closed_at === null)
            ->schema(fn (WorkCase $record) => [
                TextInput::make('decision_type')->label(self::f('decision_type'))->required()->maxLength(48),
                TextInput::make('outcome')->label(self::f('outcome'))->required()->maxLength(48),
                Textarea::make('rationale')->label(self::f('rationale'))->required()->maxLength(5000),
                KeyValue::make('conditions')->label(self::f('conditions')),
                Select::make('reverses_decision_id')->label(self::f('reverses_decision'))
                    ->options(fn () => $record->decisions()->get()->mapWithKeys(fn ($d) => [$d->id => $d->decision_type.' · '.$d->outcome])->all()),
            ])
            ->action(fn (Action $action, WorkCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CaseService::class)->decide(self::case($record), array_filter($data, fn ($v) => $v !== null && $v !== []), auth()->user()), self::done('caseDecide')));
    }

    public static function diary(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('caseDiary', $p, self::LANG)->icon('lucide-notebook-pen')
            ->schema([
                Select::make('entry_type')->label(self::f('entry_type'))->required()->live()
                    ->options(collect(['NOTE', 'CALL', 'MEETING', 'FOLLOW_UP'])->mapWithKeys(fn ($v) => [$v => __("support_actions.entry_types.{$v}")])->all()),
                Textarea::make('body')->label(self::f('body'))->required()->maxLength(10000),
                DateTimePicker::make('follow_up_at')->label(self::f('follow_up_at'))->required(fn ($get) => $get('entry_type') === 'FOLLOW_UP'),
                Select::make('visibility')->label(self::f('visibility'))->options(['INTERNAL' => __('support_actions.visibility.INTERNAL'), 'SHARED' => __('support_actions.visibility.SHARED')]),
            ])
            ->action(fn (Action $action, WorkCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(DiaryService::class)->add(self::case($record), array_filter($data, fn ($v) => $v !== null), auth()->user()), self::done('caseDiary')));
    }

    public static function reclassify(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('caseReclassify', $p, self::LANG)->icon('lucide-tags')
            ->visible(fn (WorkCase $record) => $record->closed_at === null)
            ->schema(fn (WorkCase $record) => [
                Select::make('case_subtype')->label(self::f('case_subtype'))
                    ->options(self::opts((array) (CaseType::find($record->case_type_id)?->subtypes ?? []))),
                Textarea::make('reason')->label(self::f('reason'))->required()->minLength(5)->maxLength(2000),
            ])
            ->action(fn (Action $action, WorkCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CaseService::class)->reclassify(self::case($record), $data['case_subtype'] ?? null, auth()->user(), $data['reason']), self::done('caseReclassify')));
    }

    public static function addTask(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('caseAddTask', $p, self::LANG)->icon('lucide-list-plus')
            ->visible(fn (WorkCase $record) => $record->closed_at === null)
            ->schema([
                TextInput::make('title')->label(self::f('title'))->required()->maxLength(255),
                TextInput::make('task_type')->label(self::f('task_type'))->maxLength(32),
                Select::make('assignee_user_id')->label(self::f('assignee'))->searchable()->options(fn () => self::members()),
                DateTimePicker::make('due_at')->label(self::f('due_at')),
            ])
            ->action(fn (Action $action, WorkCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CaseService::class)->addTask(self::case($record), array_filter($data, fn ($v) => $v !== null && $v !== ''), auth()->user()), self::done('caseAddTask')));
    }

    public static function taskTransition(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('caseTaskTransition', $p, self::LANG)->icon('lucide-list-checks')
            ->visible(fn (WorkCase $record) => $record->tasks()->whereIn('status', CaseTask::OPEN_STATES)->exists())
            ->schema(fn (WorkCase $record) => [
                Select::make('task_id')->label(self::f('task'))->required()
                    ->options(fn () => $record->tasks()->whereIn('status', CaseTask::OPEN_STATES)->get()->mapWithKeys(fn ($t) => [$t->id => $t->title.' ('.$t->status.')'])->all()),
                Select::make('status')->label(self::f('task_status'))->required()
                    ->options(collect(['IN_PROGRESS', 'BLOCKED', 'DONE', 'CANCELLED'])->mapWithKeys(fn ($v) => [$v => __("support_actions.task_statuses.{$v}")])->all()),
            ])
            ->action(fn (Action $action, WorkCase $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $c = self::case($record);
                $task = CaseTask::where('case_id', $c->id)->findOrFail($data['task_id']);

                return app(CaseService::class)->transitionTask($task, $data['status'], auth()->user(), null);
            }, self::done('caseTaskTransition')));
    }

    // ---------------------------------------------------------------- complaints

    public static function complaintSubmit(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('complaintSubmit', $p, self::LANG)->icon('lucide-message-square-warning')
            ->schema([
                TextInput::make('complainant_name')->label(self::f('complainant_name'))->required()->maxLength(200),
                TextInput::make('complainant_contact')->label(self::f('complainant_contact'))->maxLength(255),
                Select::make('channel')->label(self::f('channel'))->required()
                    ->options(collect(CorrespondenceService::CHANNELS)->mapWithKeys(fn ($v) => [$v => __("support_actions.channels.{$v}")])->all()),
                Textarea::make('description')->label(self::f('description'))->required()->minLength(10)->maxLength(10000),
                Toggle::make('regulatory')->label(self::f('regulatory')),
                DateTimePicker::make('received_at')->label(self::f('received_at'))->maxDate(now()),
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ComplaintService::class)->submit(self::tenant(), array_filter($data, fn ($v) => $v !== null && $v !== ''), auth()->user()), self::done('complaintSubmit')));
    }

    public static function complaintFromTicket(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('complaintFromTicket', $p, self::LANG)->icon('lucide-git-merge')->requiresConfirmation()
            ->visible(fn (SupportTicket $record) => in_array($record->type, ['COMPLAINT', 'REGULATORY_COMPLAINT'], true))
            ->action(fn (Action $action, SupportTicket $record) => WorkflowAction::run($action, $p,
                fn () => app(ComplaintService::class)->fromSupportTicket(self::tenant(), $record->id, auth()->user()), self::done('complaintFromTicket')));
    }

    public static function complaintAcknowledge(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('complaintAcknowledge', $p, self::LANG)->icon('lucide-mail-check')->requiresConfirmation()
            ->visible(fn (WorkCase $record) => self::isComplaint($record))
            ->action(fn (Action $action, WorkCase $record) => WorkflowAction::run($action, $p,
                fn () => app(ComplaintService::class)->acknowledge(self::complaint($record), auth()->user()), self::done('complaintAcknowledge')));
    }

    public static function complaintClassify(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('complaintClassify', $p, self::LANG)->icon('lucide-tag')
            ->visible(fn (WorkCase $record) => self::isComplaint($record))
            ->schema([
                Select::make('category')->label(self::f('category'))->required()->searchable()->options(fn () => self::opts(OperationsCatalogue::list('complaint_categories'))),
                Select::make('severity')->label(self::f('severity'))->required()
                    ->options(collect(['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])->mapWithKeys(fn ($v) => [$v => __("support_actions.severities.{$v}")])->all()),
                Toggle::make('regulatory')->label(self::f('regulatory')),
            ])
            ->action(fn (Action $action, WorkCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ComplaintService::class)->classify(self::complaint($record), $data['category'], $data['severity'], isset($data['regulatory']) ? (bool) $data['regulatory'] : null, auth()->user()),
                self::done('complaintClassify')));
    }

    public static function complaintAssign(): Action
    {
        $p = 'cases.assign';

        return WorkflowAction::make('complaintAssign', $p, self::LANG)->icon('lucide-user-search')
            ->visible(fn (WorkCase $record) => self::isComplaint($record))
            ->schema([
                Select::make('owner_user_id')->label(self::f('investigator'))->required()->searchable()->options(fn () => self::members()),
                Textarea::make('reason')->label(self::f('reason'))->maxLength(500),
            ])
            ->action(fn (Action $action, WorkCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ComplaintService::class)->assignInvestigator(self::complaint($record), $data['owner_user_id'], auth()->user(), $data['reason'] ?? null), self::done('complaintAssign')));
    }

    public static function complaintInvestigate(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('complaintInvestigate', $p, self::LANG)->icon('lucide-search')->requiresConfirmation()
            ->visible(fn (WorkCase $record) => self::isComplaint($record))
            ->action(fn (Action $action, WorkCase $record) => WorkflowAction::run($action, $p,
                fn () => app(ComplaintService::class)->investigate(self::complaint($record), auth()->user()), self::done('complaintInvestigate')));
    }

    public static function complaintResolution(): Action
    {
        $p = 'cases.decide';

        return WorkflowAction::make('complaintResolution', $p, self::LANG)->icon('lucide-scale')
            ->visible(fn (WorkCase $record) => self::isComplaint($record))
            ->schema([
                Select::make('outcome')->label(self::f('outcome'))->required()
                    ->options(collect(['UPHELD', 'PARTIALLY_UPHELD', 'NOT_UPHELD'])->mapWithKeys(fn ($v) => [$v => __("support_actions.outcomes.{$v}")])->all()),
                Textarea::make('resolution_summary')->label(self::f('resolution_summary'))->required()->minLength(10)->maxLength(10000),
                TextInput::make('root_cause')->label(self::f('root_cause'))->maxLength(64),
                TextInput::make('redress_amount')->label(self::f('redress_amount'))->numeric()->minValue(0),
                Select::make('resolution_reason')->label(self::f('resolution_reason'))->options(fn () => self::opts(OperationsCatalogue::list('complaint_resolution_reasons'))),
            ])
            ->action(fn (Action $action, WorkCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ComplaintService::class)->proposeResolution(self::complaint($record), array_filter($data, fn ($v) => $v !== null && $v !== ''), auth()->user()),
                self::done('complaintResolution')));
    }

    public static function complaintCommunicate(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('complaintCommunicate', $p, self::LANG)->icon('lucide-send')
            ->visible(fn (WorkCase $record) => self::isComplaint($record))
            ->schema(fn (WorkCase $record) => [
                Select::make('correspondence_id')->label(self::f('correspondence'))->required()
                    ->options(fn () => DB::table('correspondence_register')->where('case_id', $record->id)->where('direction', 'OUTBOUND')->orderBy('created_at')
                        ->get()->mapWithKeys(fn ($c) => [$c->id => $c->reference_number.' · '.$c->subject_line.' ('.$c->status.')'])->all()),
            ])
            ->action(fn (Action $action, WorkCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ComplaintService::class)->communicate(self::complaint($record), $data['correspondence_id'], auth()->user()), self::done('complaintCommunicate')));
    }

    public static function complaintEscalate(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('complaintEscalate', $p, self::LANG)->icon('lucide-arrow-up-right')
            ->visible(fn (WorkCase $record) => self::isComplaint($record))
            ->schema([
                Select::make('level')->label(self::f('level'))->required()->options(['NATIONAL' => __('support_actions.levels.NATIONAL'), 'CIMA' => __('support_actions.levels.CIMA')]),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(1000),
                TextInput::make('reference')->label(self::f('reference'))->maxLength(120),
            ])
            ->action(fn (Action $action, WorkCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ComplaintService::class)->escalate(self::complaint($record), $data['level'], $data['reason'], $data['reference'] ?? null, auth()->user()), self::done('complaintEscalate')));
    }

    public static function complaintAdvance(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('complaintAdvance', $p, self::LANG)->icon('lucide-arrow-right-left')
            ->visible(fn (WorkCase $record) => self::isComplaint($record))
            ->schema([
                Select::make('event')->label(self::f('event'))->required()
                    ->options(collect(['request_info', 'info_received', 'reinvestigate', 'close'])->mapWithKeys(fn ($v) => [$v => __("support_actions.events.{$v}")])->all()),
                Textarea::make('reason')->label(self::f('reason'))->maxLength(1000),
            ])
            ->action(fn (Action $action, WorkCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ComplaintService::class)->advance(self::complaint($record), $data['event'], auth()->user(), $data['reason'] ?? null), self::done('complaintAdvance')));
    }

    // ---------------------------------------------------------------- support tickets

    public static function ticketOpen(): Action
    {
        $p = 'support.manage';

        return WorkflowAction::make('ticketOpen', $p, self::LANG)->icon('lucide-plus')
            ->schema([
                self::customerSelect(),
                Select::make('type')->label(self::f('ticket_type'))->required()
                    ->options(collect(['SUPPORT', 'COMPLAINT', 'REGULATORY_COMPLAINT'])->mapWithKeys(fn ($v) => [$v => __("support_actions.ticket_types.{$v}")])->all()),
                TextInput::make('category')->label(self::f('category'))->required()->maxLength(64),
                Select::make('priority')->label(self::f('priority'))->required()
                    ->options(collect(['LOW', 'NORMAL', 'HIGH', 'URGENT'])->mapWithKeys(fn ($v) => [$v => __("support_actions.priorities.{$v}")])->all()),
                TextInput::make('subject')->label(self::f('subject'))->required()->maxLength(200),
                Textarea::make('description')->label(self::f('description'))->required()->minLength(20)->maxLength(10000),
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SupportTicketService::class)->open(self::tenant(), array_filter($data, fn ($v) => $v !== null && $v !== ''), auth()->user()), self::done('ticketOpen')));
    }

    public static function ticketTransition(): Action
    {
        $p = 'support.manage';

        return WorkflowAction::make('ticketTransition', $p, self::LANG)->icon('lucide-arrow-right-left')
            ->visible(fn (SupportTicket $record) => ! in_array($record->status, ['CLOSED', 'CANCELLED'], true))
            ->schema([
                Select::make('to_status')->label(self::f('to_status'))->required()
                    ->options(collect(['TRIAGED', 'IN_PROGRESS', 'WAITING_CUSTOMER', 'ESCALATED', 'RESOLVED', 'CLOSED', 'REOPENED', 'CANCELLED'])
                        ->mapWithKeys(fn ($v) => [$v => __("support_actions.ticket_statuses.{$v}")])->all()),
                Textarea::make('message')->label(self::f('message'))->required()->minLength(5)->maxLength(5000),
                Select::make('assigned_to')->label(self::f('assignee'))->searchable()->options(fn () => self::members()),
            ])
            ->action(fn (Action $action, SupportTicket $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                try {
                    return app(SupportTicketService::class)->transition(self::tenant(), $record->id, array_filter($data, fn ($v) => $v !== null), auth()->user());
                } catch (\DomainException $e) {
                    throw new ApiProblemException('TICKET_TRANSITION_INVALID', 422, $e->getMessage());
                }
            }, self::done('ticketTransition')));
    }

    /** Customer (party) picker limited to this tenant's customers, as the API's tenant_customers check requires. */
    public static function customerSelect(): Select
    {
        $customers = fn () => DB::table('tenant_customers as tc')->join('parties as p', 'p.id', '=', 'tc.party_id')->where('tc.tenant_id', self::tenant());

        return Select::make('party_id')->label(self::f('customer'))->searchable()
            ->getSearchResultsUsing(fn (string $search) => $customers()->where('p.display_name', 'ilike', '%'.$search.'%')->limit(20)->pluck('p.display_name', 'p.id')->all())
            ->getOptionLabelUsing(fn ($value) => $customers()->where('p.id', $value)->value('p.display_name'));
    }

    /** Active members of the current tenant (same population CaseService::assign accepts). */
    private static function members(): array
    {
        $tenant = self::tenant();

        return User::where('status', 'ACTIVE')->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenant)->where('status', 'ACTIVE'))->pluck('full_name', 'id')->all();
    }
}
