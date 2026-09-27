<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Partners\PartnerBook;
use App\Application\Underwriting\ProposalService;
use App\Application\Underwriting\UnderwritingCaseMachine;
use App\Application\Underwriting\UnderwritingService;
use App\Models\Proposal;
use App\Models\UnderwritingCase;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * Proposal detail-page actions. Same services / permissions as:
 *   submit              POST proposals/{p}/submit                           (route has no permission gate)  ProposalService::submit
 *   requestInformation  POST proposals/{p}/information-requests             underwriting.decide             ProposalService::requestInformation
 *   answer              POST proposals/{p}/resubmit                         (route has no permission gate)  ProposalService::resubmit
 *   decide              POST underwriting/cases/{c}/decision                underwriting.decide             UnderwritingService::decide
 *   counterOffer        POST underwriting/cases/{c}/decision (COUNTEROFFERED) underwriting.decide           UnderwritingService::decide
 */
final class ProposalActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([self::submit(), self::requestInformation(), self::answer(), self::decide(), self::counterOffer()])
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
