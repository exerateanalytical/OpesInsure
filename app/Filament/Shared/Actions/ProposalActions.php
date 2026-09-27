<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Partners\PartnerBook;
use App\Application\Underwriting\ProposalService;
use App\Application\Underwriting\ProposalMachine;
use App\Application\Underwriting\UnderwritingCaseMachine;
use App\Application\Underwriting\UnderwritingService;
use App\Models\Document;
use App\Models\Proposal;
use App\Models\ProposalDocument;
use App\Models\UnderwritingCase;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

/**
 * Proposal detail-page actions. Same services / permissions as:
 *   submit              POST proposals/{p}/submit                           (route has no permission gate)  ProposalService::submit
 *   requestInformation  POST proposals/{p}/information-requests             underwriting.decide             ProposalService::requestInformation
 *   answer              POST proposals/{p}/resubmit                         (route has no permission gate)  ProposalService::resubmit
 *   decide              POST underwriting/cases/{c}/decision                underwriting.decide             UnderwritingService::decide
 *   counterOffer        POST underwriting/cases/{c}/decision (COUNTEROFFERED) underwriting.decide           UnderwritingService::decide
 * Assisted-channel proposal completion (labels: issuance_maker_checker.actions.*; channel AGENT; partner-book checked like the API):
 *   disclosureAnswers   PUT  proposals/{p}/disclosure/answers, proposals/{p}/disclosures  (no gate)   ProposalService::answer
 *   attest              POST proposals/{p}/disclosure/submit, proposals/{p}/disclosures/attest (no gate) ProposalService::attest
 *   declare             POST proposals/{p}/declarations                       (no permission gate)  ProposalService::declare
 *   coverTerms          PUT  proposals/{p}/cover-terms                        (no permission gate)  ProposalService::selectCoverTerms
 *   attachDocument      POST proposals/{p}/documents                          (no permission gate)  ProposalService::attachDocument
 *   reviewDocument      POST proposals/{p}/documents/{d}/review               documents.review      ProposalService::verifyDocument
 *   withdraw            POST proposals/{p}/withdraw                           (no permission gate)  ProposalService::withdraw
 */
final class ProposalActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([self::disclosureAnswers(), self::attest(), self::declare(), self::coverTerms(), self::attachDocument(), self::reviewDocument(),
            self::submit(), self::requestInformation(), self::answer(), self::decide(), self::counterOffer(), self::withdraw()])
            ->label(__('workflow_actions.proposal_group'))->icon('lucide-zap')->button();
    }

    public static function submit(): Action
    {
        return WorkflowAction::make('proposalSubmit', null)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn (Proposal $record) => $record->status === 'DRAFT')
            ->action(fn (Action $action, Proposal $record) => WorkflowAction::run($action, null, fn () => app(ProposalService::class)->submit(self::book($record), auth()->user())));
    }

    public static function requestInformation(): Action
    {
        $p = 'underwriting.decide';

        return WorkflowAction::make('proposalRequestInformation', $p)->icon('lucide-circle-help')
            ->schema([
                Repeater::make('items')->label(__('workflow_actions.fields.items'))->minItems(1)->maxItems(50)->required()->schema([
                    TextInput::make('code')->label(__('workflow_actions.fields.code'))->maxLength(64),
                    Textarea::make('description')->label(__('workflow_actions.fields.description'))->required()->minLength(3)->maxLength(1000),
                ]),
                Textarea::make('message')->label(__('workflow_actions.fields.message'))->maxLength(4000),
            ])
            ->action(fn (Action $action, Proposal $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ProposalService::class)->requestInformation(
                $record, collect($data['items'])->map(fn ($i) => array_filter($i, fn ($v) => filled($v)))->values()->all(), filled($data['message'] ?? null) ? $data['message'] : null, auth()->user())));
    }

    public static function answer(): Action
    {
        return WorkflowAction::make('proposalAnswer', null)->icon('lucide-messages-square')
            ->visible(fn (Proposal $record) => ! empty($record->information_request))
            ->schema([Textarea::make('response')->label(__('workflow_actions.fields.response'))->maxLength(4000)])
            ->action(fn (Action $action, Proposal $record, array $data) => WorkflowAction::run($action, null,
                fn () => app(ProposalService::class)->resubmit(self::book($record), auth()->user(), filled($data['response'] ?? null) ? $data['response'] : null)));
    }

    public static function decide(): Action
    {
        $p = 'underwriting.decide';

        return WorkflowAction::make('proposalDecide', $p)->icon('lucide-scale')->requiresConfirmation()
            ->visible(fn (Proposal $record) => self::case($record) !== null)
            ->schema([
                Select::make('decision')->label(__('workflow_actions.fields.decision'))->required()->live()
                    ->options(WorkflowAction::options(['APPROVED', 'CONDITIONAL', 'DECLINED'], 'underwriting')),
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->required()->minLength(20)->maxLength(4000),
                KeyValue::make('conditions')->label(__('workflow_actions.fields.conditions'))->visible(fn (callable $get) => $get('decision') === 'CONDITIONAL')->required(fn (callable $get) => $get('decision') === 'CONDITIONAL'),
            ])
            ->action(fn (Action $action, Proposal $record, array $data) => WorkflowAction::run($action, $p, fn () => self::decideCase($record, $data)));
    }

    public static function counterOffer(): Action
    {
        $p = 'underwriting.decide';

        return WorkflowAction::make('proposalCounterOffer', $p)->icon('lucide-arrow-left-right')->requiresConfirmation()
            ->visible(fn (Proposal $record) => self::case($record) !== null)
            ->schema([
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                Textarea::make('notes')->label(__('workflow_actions.fields.counter_terms'))->required()->minLength(20)->maxLength(4000),
                KeyValue::make('conditions')->label(__('workflow_actions.fields.conditions')),
            ])
            ->action(fn (Action $action, Proposal $record, array $data) => WorkflowAction::run($action, $p, fn () => self::decideCase($record, ['decision' => 'COUNTEROFFERED'] + $data)));
    }

    public static function disclosureAnswers(): Action
    {
        return IssuanceActions::make('proposalDisclosureAnswers', null)->icon('lucide-list-checks')
            ->visible(fn (Proposal $record) => in_array($record->status, ProposalMachine::ANSWERABLE, true))
            ->fillForm(fn (Proposal $record) => ['answers' => collect($record->disclosures ?? [])->map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v))->all()])
            ->schema([KeyValue::make('answers')->label(__('issuance_maker_checker.fields.answers'))->keyLabel(__('issuance_maker_checker.fields.question'))
                ->valueLabel(__('issuance_maker_checker.fields.answer'))->required()])
            ->action(fn (Action $action, Proposal $record, array $data) => WorkflowAction::run($action, null,
                fn () => app(ProposalService::class)->answer(self::book($record), self::typed($data['answers'] ?? []), auth()->user()), IssuanceActions::done('proposalDisclosureAnswers')));
    }

    public static function attest(): Action
    {
        return IssuanceActions::make('proposalAttest', null)->icon('lucide-signature')->requiresConfirmation()
            ->visible(fn (Proposal $record) => ! $record->attested_at && in_array($record->status, ProposalMachine::ANSWERABLE, true))
            ->action(fn (Action $action, Proposal $record) => WorkflowAction::run($action, null,
                fn () => app(ProposalService::class)->attest(self::book($record), auth()->user(), [], 'AGENT', self::evidence()), IssuanceActions::done('proposalAttest')));
    }

    public static function declare(): Action
    {
        return IssuanceActions::make('proposalDeclare', null)->icon('lucide-file-check')
            ->visible(fn (Proposal $record) => ! in_array($record->status, ProposalMachine::TERMINAL, true))
            ->schema([CheckboxList::make('codes')->label(__('issuance_maker_checker.fields.codes'))->required()
                ->options(fn (Proposal $record) => collect(app(ProposalService::class)->checklist($record)['declarations'])->reject(fn ($d) => $d['accepted'])
                    ->mapWithKeys(fn ($d) => [$d['code'] => $d['code'].' — '.Str::limit((string) $d['statement'], 120)])->all())])
            ->action(fn (Action $action, Proposal $record, array $data) => WorkflowAction::run($action, null, function () use ($record, $data) {
                $p = self::book($record);
                foreach (array_unique($data['codes']) as $code) {
                    $p = app(ProposalService::class)->declare($p, strtoupper((string) $code), auth()->user(), 'AGENT', self::evidence());
                }

                return $p;
            }, IssuanceActions::done('proposalDeclare')));
    }

    public static function coverTerms(): Action
    {
        return IssuanceActions::make('proposalCoverTerms', null)->icon('lucide-calendar-range')
            ->visible(fn (Proposal $record) => in_array($record->status, ProposalMachine::ANSWERABLE, true))
            ->schema([
                TextInput::make('effective_rule')->label(__('issuance_maker_checker.fields.effective_rule'))->maxLength(24),
                DatePicker::make('start_date')->label(__('issuance_maker_checker.fields.start_date')),
                Select::make('duration_unit')->label(__('issuance_maker_checker.fields.duration_unit'))
                    ->options(collect(['DAY', 'MONTH', 'CUSTOM'])->mapWithKeys(fn ($v) => [$v => __("issuance_maker_checker.codes.duration_unit.{$v}")])->all()),
                TextInput::make('duration_value')->label(__('issuance_maker_checker.fields.duration_value'))->integer()->minValue(1)->maxValue(366),
                TextInput::make('instalment_plan')->label(__('issuance_maker_checker.fields.instalment_plan'))->maxLength(16),
            ])
            ->action(fn (Action $action, Proposal $record, array $data) => WorkflowAction::run($action, null,
                fn () => app(ProposalService::class)->selectCoverTerms(self::book($record), self::coverTermsInput($data), auth()->user()), IssuanceActions::done('proposalCoverTerms')));
    }

    public static function attachDocument(): Action
    {
        return IssuanceActions::make('proposalAttachDocument', null)->icon('lucide-paperclip')
            ->visible(fn (Proposal $record) => ! in_array($record->status, [...ProposalMachine::TERMINAL, 'PAYMENT_PENDING', 'APPROVED'], true))
            ->schema([
                Select::make('requirement_code')->label(__('issuance_maker_checker.fields.requirement_code'))->required()
                    ->options(fn (Proposal $record) => collect(app(ProposalService::class)->requiredDocuments($record))->mapWithKeys(fn ($r) => [$r['code'] => ($r['name'] ?? $r['code']).' ('.$r['status'].')'])->all()),
                Select::make('document_id')->label(__('issuance_maker_checker.fields.document'))->required()->searchable()
                    ->options(fn (Proposal $record) => Document::where(['tenant_id' => $record->tenant_id, 'party_id' => $record->party_id])->latest()->limit(200)->get()
                        ->mapWithKeys(fn (Document $d) => [$d->id => ($d->getAttribute('original_filename') ?? $d->getAttribute('file_name') ?? $d->id).' · '.$d->created_at?->format('Y-m-d')])->all()),
            ])
            ->action(fn (Action $action, Proposal $record, array $data) => WorkflowAction::run($action, null,
                fn () => app(ProposalService::class)->attachDocument(self::book($record), Document::where('tenant_id', $record->tenant_id)->findOrFail($data['document_id']), $data['requirement_code']),
                IssuanceActions::done('proposalAttachDocument')));
    }

    public static function reviewDocument(): Action
    {
        $p = 'documents.review';

        return IssuanceActions::make('proposalReviewDocument', $p)->icon('lucide-file-search')
            ->visible(fn (Proposal $record) => ProposalDocument::where('proposal_id', $record->id)->exists())
            ->schema([
                Select::make('document_id')->label(__('issuance_maker_checker.fields.document'))->required()
                    ->options(fn (Proposal $record) => ProposalDocument::where('proposal_id', $record->id)->get()->mapWithKeys(fn ($l) => [$l->document_id => $l->requirement_code.' · '.$l->status])->all()),
                Select::make('decision')->label(__('issuance_maker_checker.fields.decision'))->required()
                    ->options(collect(['VERIFIED', 'REJECTED'])->mapWithKeys(fn ($v) => [$v => __("issuance_maker_checker.codes.review.{$v}")])->all()),
                Textarea::make('notes')->label(__('issuance_maker_checker.fields.notes'))->required()->maxLength(2000),
            ])
            ->action(fn (Action $action, Proposal $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ProposalService::class)->verifyDocument($record, Document::where('tenant_id', $record->tenant_id)->findOrFail($data['document_id']), $data['decision'], $data['notes'], auth()->user()),
                IssuanceActions::done('proposalReviewDocument')));
    }

    public static function withdraw(): Action
    {
        return IssuanceActions::make('proposalWithdraw', null)->icon('lucide-circle-slash')->color('danger')->requiresConfirmation()
            ->visible(fn (Proposal $record) => ! in_array($record->status, [...ProposalMachine::TERMINAL, 'APPROVED'], true))
            ->schema([Textarea::make('reason')->label(__('issuance_maker_checker.fields.reason'))->maxLength(2000)])
            ->action(fn (Action $action, Proposal $record, array $data) => WorkflowAction::run($action, null,
                fn () => app(ProposalService::class)->withdraw(self::book($record), auth()->user(), filled($data['reason'] ?? null) ? $data['reason'] : null), IssuanceActions::done('proposalWithdraw')));
    }

    /** Same input shape as PUT proposals/{p}/cover-terms. */
    public static function coverTermsInput(array $data): array
    {
        $in = array_filter(['effective_rule' => $data['effective_rule'] ?? null, 'start_date' => $data['start_date'] ?? null, 'instalment_plan' => $data['instalment_plan'] ?? null], fn ($v) => filled($v));
        if (filled($data['duration_unit'] ?? null)) {
            $in['duration'] = array_filter(['unit' => $data['duration_unit'], 'value' => filled($data['duration_value'] ?? null) ? (int) $data['duration_value'] : null], fn ($v) => $v !== null);
        }

        return $in;
    }

    /** KeyValue yields strings; numbers, booleans and JSON are restored so the questionnaire validation sees typed answers. */
    public static function typed(array $answers): array
    {
        return collect($answers)->map(function ($v) {
            $v = is_string($v) ? trim($v) : $v;

            return match (true) {
                $v === 'true' => true,
                $v === 'false' => false,
                is_string($v) && is_numeric($v) => $v + 0,
                is_string($v) && (str_starts_with($v, '[') || str_starts_with($v, '{')) && json_validate($v) => json_decode($v, true),
                default => $v,
            };
        })->all();
    }

    private static function evidence(): array
    {
        return ['ip' => request()->ip(), 'user_agent' => request()->userAgent()];
    }

    private static function decideCase(Proposal $proposal, array $data): Proposal
    {
        $d = ['decision' => $data['decision'], 'reason_code' => $data['reason_code'], 'notes' => $data['notes']];
        if (! empty($data['conditions'])) {
            $d['conditions'] = $data['conditions'];
        }

        return app(UnderwritingService::class)->decide(self::case($proposal) ?? abort(404), $d, auth()->user());
    }

    private static function case(Proposal $proposal): ?UnderwritingCase
    {
        return UnderwritingCase::where('tenant_id', $proposal->tenant_id)->where('proposal_id', $proposal->id)->whereIn('status', UnderwritingCaseMachine::TRANSITIONS['decide'])->latest('created_at')->first();
    }

    private static function book(Proposal $proposal): Proposal
    {
        app(PartnerBook::class)->assertInBook(auth()->user(), $proposal->party_id);

        return $proposal;
    }
}
