<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Cases\Models\WorkCase;
use App\Application\Correspondence\CorrespondenceService;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/**
 * REQ-COR-001 correspondence register actions (CorrespondenceRegister page). Same permission, validation and service as
 * App\Application\Correspondence\Http\CorrespondenceController (routes/cases.php):
 *   corRegister   POST correspondence                 cases.manage  CorrespondenceService::register
 *   corDispatch   POST correspondence/{id}/dispatch   cases.manage  CorrespondenceService::recordDispatch
 *   corOutcome    POST correspondence/{id}/outcome    cases.manage  CorrespondenceService::recordOutcome
 * Proof of dispatch is write-once; the service refuses a second dispatch and a failure without reason.
 */
final class CorrespondenceActions
{
    private const LANG = 'doc_uw_actions';

    private const P = 'cases.manage';

    public static function register(): Action
    {
        $p = self::P;
        $opts = fn (array $values, string $group) => collect($values)->mapWithKeys(fn ($v) => [$v => WorkflowAction::optional("doc_uw_actions.codes.{$group}.{$v}") ?? $v])->all();

        return WorkflowAction::make('corRegister', $p, self::LANG)->icon('lucide-mail-plus')
            ->schema([
                Select::make('direction')->label(__('doc_uw_actions.fields.direction'))->required()->options($opts(['INBOUND', 'OUTBOUND'], 'direction'))->live(),
                Select::make('channel')->label(__('doc_uw_actions.fields.channel'))->required()->options($opts(CorrespondenceService::CHANNELS, 'channel')),
                Select::make('counterparty_type')->label(__('doc_uw_actions.fields.counterparty_type'))->required()->options($opts(CorrespondenceService::COUNTERPARTY_TYPES, 'counterparty')),
                TextInput::make('counterparty_name')->label(__('doc_uw_actions.fields.counterparty_name'))->required()->maxLength(200),
                TextInput::make('counterparty_contact')->label(__('doc_uw_actions.fields.counterparty_contact'))->maxLength(255),
                Select::make('case_id')->label(__('doc_uw_actions.fields.case'))->searchable()
                    ->options(fn () => WorkCase::query()->where('tenant_id', app(TenantContext::class)->id())->latest('created_at')->limit(200)->pluck('case_number', 'id')->all()),
                TextInput::make('subject_line')->label(__('doc_uw_actions.fields.subject_line'))->required()->maxLength(255),
                Textarea::make('summary')->label(__('doc_uw_actions.fields.summary'))->maxLength(10000),
                TextInput::make('external_reference')->label(__('doc_uw_actions.fields.external_reference'))->maxLength(120),
                DateTimePicker::make('received_at')->label(__('doc_uw_actions.fields.received_at'))->maxDate(now())
                    ->visible(fn ($get) => $get('direction') === 'INBOUND'),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $d = array_filter($data, fn ($v) => $v !== null && $v !== '');

                return WorkflowAction::run($action, $p, fn () => app(CorrespondenceService::class)->register(app(TenantContext::class)->id(), $d, auth()->user()),
                    __('doc_uw_actions.corRegister.done'));
            });
    }

    public static function dispatch(): Action
    {
        $p = self::P;

        return WorkflowAction::make('corDispatch', $p, self::LANG)->icon('lucide-send')
            ->visible(fn (array $record) => $record['direction'] === 'OUTBOUND' && $record['status'] === 'DRAFT')
            ->schema([
                Select::make('proof_type')->label(__('doc_uw_actions.fields.proof_type'))->required()
                    ->options(collect(CorrespondenceService::PROOF_TYPES)->mapWithKeys(fn ($v) => [$v => WorkflowAction::optional("doc_uw_actions.codes.proof.{$v}") ?? $v])->all()),
                TextInput::make('proof_reference')->label(__('doc_uw_actions.fields.proof_reference'))->maxLength(160),
                DateTimePicker::make('dispatched_at')->label(__('doc_uw_actions.fields.dispatched_at'))->maxDate(now()),
                DateTimePicker::make('delivered_at')->label(__('doc_uw_actions.fields.delivered_at'))->maxDate(now())->afterOrEqual('dispatched_at'),
            ])
            ->action(function (Action $action, array $record, array $data) use ($p) {
                $d = array_filter($data, fn ($v) => $v !== null && $v !== '');

                return WorkflowAction::run($action, $p, fn () => app(CorrespondenceService::class)->recordDispatch(app(TenantContext::class)->id(), (string) $record['id'], $d, auth()->user()),
                    __('doc_uw_actions.corDispatch.done'));
            });
    }

    public static function outcome(): Action
    {
        $p = self::P;

        return WorkflowAction::make('corOutcome', $p, self::LANG)->icon('lucide-mail-check')
            ->visible(fn (array $record) => $record['status'] === 'DISPATCHED')
            ->schema([
                Toggle::make('delivered')->label(__('doc_uw_actions.fields.delivered'))->default(true)->live(),
                Textarea::make('reason')->label(__('doc_uw_actions.fields.failure_reason'))->maxLength(1000)
                    ->required(fn ($get) => ! $get('delivered')),
            ])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CorrespondenceService::class)->recordOutcome(app(TenantContext::class)->id(), (string) $record['id'], (bool) $data['delivered'], filled($data['reason'] ?? null) ? $data['reason'] : null, auth()->user()),
                __('doc_uw_actions.corOutcome.done')));
    }
}
