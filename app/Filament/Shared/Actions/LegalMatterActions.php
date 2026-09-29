<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Claims\Recovery\Litigation\LegalMatterService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * Litigation matters (UI coverage batch 25). Same service, same permission, same validation as the API routes.
 *   legalOpen      POST legal-matters                  legal.matters.manage  LegalMatterService::open
 *   legalHearing   POST legal-matters/{m}/hearings     legal.matters.manage  LegalMatterService::scheduleHearing
 *   legalDeadline  POST legal-matters/{m}/deadlines    legal.matters.manage  LegalMatterService::addDeadline
 *   legalCost      POST legal-matters/{m}/costs        legal.matters.manage  LegalMatterService::addCost
 *   legalOutcome   POST legal-matters/{m}/outcome      legal.matters.manage  LegalMatterService::recordOutcome
 */
final class LegalMatterActions
{
    private const L = RiskTransferSupport::L;

    private const P = 'legal.matters.manage';

    public static function legalOpen(): Action
    {
        $p = self::P;

        return WorkflowAction::make('legalOpen', $p, self::L)->icon('lucide-gavel')
            ->schema([
                Select::make('role')->label(RiskTransferSupport::f('legal_role'))->options(RiskTransferSupport::codes(LegalMatterService::ROLES))->required(),
                TextInput::make('title')->label(RiskTransferSupport::f('title'))->maxLength(255),
                TextInput::make('court')->label(RiskTransferSupport::f('court'))->required()->maxLength(255),
                TextInput::make('court_reference')->label(RiskTransferSupport::f('court_reference'))->maxLength(120),
                TextInput::make('jurisdiction')->label(RiskTransferSupport::f('jurisdiction'))->length(2),
                TextInput::make('opposing_party_name')->label(RiskTransferSupport::f('opposing_party_name'))->maxLength(255),
                RiskTransferSupport::claimSelect(),
                TextInput::make('claimed_amount_minor')->label(RiskTransferSupport::f('claimed_amount_minor'))->integer()->minValue(0),
                TextInput::make('currency')->label(RiskTransferSupport::f('currency'))->length(3)->default('XAF'),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $d = RiskTransferSupport::clean($data);
                if (isset($d['claimed_amount_minor'])) {
                    $d['claimed_amount_minor'] = (int) $d['claimed_amount_minor'];
                }

                return WorkflowAction::run($action, $p, fn () => app(LegalMatterService::class)->open(RiskTransferSupport::tenant(), $d, RiskTransferSupport::user()), __(self::L.'.legalOpen.done'));
            });
    }

    public static function legalHearing(): Action
    {
        $p = self::P;

        return WorkflowAction::make('legalHearing', $p, self::L)->icon('lucide-calendar-clock')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'ACTIVE')
            ->schema([
                DateTimePicker::make('scheduled_at')->label(RiskTransferSupport::f('scheduled_at'))->required(),
                TextInput::make('location')->label(RiskTransferSupport::f('location'))->maxLength(255),
                TextInput::make('purpose')->label(RiskTransferSupport::f('purpose'))->maxLength(255),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(LegalMatterService::class)->scheduleHearing(RiskTransferSupport::tenant(), WorkflowAction::id($record), RiskTransferSupport::clean($data), RiskTransferSupport::user()),
                __(self::L.'.legalHearing.done')));
    }

    public static function legalDeadline(): Action
    {
        $p = self::P;

        return WorkflowAction::make('legalDeadline', $p, self::L)->icon('lucide-alarm-clock')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'ACTIVE')
            ->schema([
                TextInput::make('description')->label(RiskTransferSupport::f('description'))->required()->maxLength(255),
                DateTimePicker::make('due_at')->label(RiskTransferSupport::f('due_at'))->required(),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(LegalMatterService::class)->addDeadline(RiskTransferSupport::tenant(), WorkflowAction::id($record), $data['description'], $data['due_at'], RiskTransferSupport::user()),
                __(self::L.'.legalDeadline.done')));
    }

    public static function legalCost(): Action
    {
        $p = self::P;

        return WorkflowAction::make('legalCost', $p, self::L)->icon('lucide-banknote')
            ->schema([
                Select::make('cost_type')->label(RiskTransferSupport::f('cost_type'))->options(RiskTransferSupport::codes(LegalMatterService::COST_TYPES))->required(),
                TextInput::make('amount_minor')->label(RiskTransferSupport::f('amount_minor'))->integer()->minValue(1)->required(),
                TextInput::make('currency')->label(RiskTransferSupport::f('currency'))->length(3),
                TextInput::make('description')->label(RiskTransferSupport::f('description'))->maxLength(255),
                DatePicker::make('incurred_on')->label(RiskTransferSupport::f('incurred_on')),
                DatePicker::make('due_at')->label(RiskTransferSupport::f('due_at')),
            ])
            ->action(function (Action $action, mixed $record, array $data) use ($p) {
                $d = RiskTransferSupport::clean($data);
                $d['amount_minor'] = (int) $d['amount_minor'];

                return WorkflowAction::run($action, $p, fn () => app(LegalMatterService::class)->addCost(RiskTransferSupport::tenant(), WorkflowAction::id($record), $d, RiskTransferSupport::user()),
                    __(self::L.'.legalCost.done'));
            });
    }

    public static function legalOutcome(): Action
    {
        $p = self::P;

        return WorkflowAction::make('legalOutcome', $p, self::L)->icon('lucide-scale')->color('success')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'ACTIVE')
            ->schema([
                Select::make('outcome')->label(RiskTransferSupport::f('outcome'))->options(RiskTransferSupport::codes(LegalMatterService::OUTCOMES))->required(),
                TextInput::make('amount_minor')->label(RiskTransferSupport::f('amount_minor'))->integer()->minValue(0),
                Textarea::make('notes')->label(RiskTransferSupport::f('notes'))->maxLength(4000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(LegalMatterService::class)->recordOutcome(RiskTransferSupport::tenant(), WorkflowAction::id($record), $data['outcome'],
                    ($data['amount_minor'] ?? '') === '' || $data['amount_minor'] === null ? null : (int) $data['amount_minor'], ($data['notes'] ?? null) ?: null, RiskTransferSupport::user()),
                __(self::L.'.legalOutcome.done')));
    }
}
