<?php

declare(strict_types=1);

namespace App\Filament\Shared\Components;

use App\Models\Policy;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Facades\DB;

/**
 * Read context for the policy operations actions: suspensions, cancellations, the renewal machine, premium instalments,
 * recovery cases, portfolio transfers and special-policy schedule items — the records those actions pick from and change.
 */
final class PolicyServicingContext
{
    /** @var array<string, array{0: string, 1: list<string>, 2: string}> section => [table, columns, order column] */
    private const SECTIONS = [
        'suspensions' => ['policy_suspensions', ['status', 'source', 'reason_code', 'suspended_at', 'reinstated_at'], 'created_at'],
        'cancellations' => ['policy_cancellations', ['status', 'reason_code', 'effective_at', 'refund_minor', 'decided_at'], 'created_at'],
        'renewals' => ['renewal_cases', ['status', 'due_on', 'window_days', 'closed_reason', 'updated_at'], 'created_at'],
        'instalments' => ['policy_premium_instalments', ['due_date', 'amount_minor', 'paid_minor', 'status', 'grace_ends_on'], 'due_date'],
        'recovery_cases' => ['policy_recovery_cases', ['case_number', 'status', 'reason_code', 'arrears_minor', 'decided_at'], 'created_at'],
        'schedule_items' => ['policy_schedule_items', ['item_key', 'display_name', 'category', 'sum_insured_minor', 'effective_from'], 'created_at'],
    ];

    public static function tab(): Tab
    {
        $sections = [];
        foreach (self::SECTIONS as $key => [$table, $columns, $order]) {
            $sections[] = Section::make(__("workflow_actions.policy_context.{$key}"))->collapsible()->schema([
                RepeatableEntry::make("servicing_{$key}")->hiddenLabel()->placeholder(__('workflow_actions.policy_context.empty'))
                    ->state(fn (?Policy $record) => $record ? self::rows($table, $columns, $order, $record) : [])
                    ->columns(['default' => 1, 'md' => count($columns)])
                    ->schema(array_map(fn (string $c) => TextEntry::make($c)->label(__("workflow_actions.policy_context.columns.{$c}"))->placeholder('—'), $columns)),
            ]);
        }
        $sections[] = Section::make(__('workflow_actions.policy_context.transfers'))->collapsible()->schema([
            RepeatableEntry::make('servicing_transfers')->hiddenLabel()->placeholder(__('workflow_actions.policy_context.empty'))
                ->state(fn (?Policy $record) => $record ? DB::table('policy_portfolio_transfers as t')->join('policy_portfolio_transfer_items as i', 'i.transfer_id', '=', 't.id')
                    ->where('t.tenant_id', $record->tenant_id)->where('i.policy_id', $record->id)->orderByDesc('t.created_at')->limit(20)
                    ->get(['t.scope', 't.status', 't.reason_code', 'i.status as item_status', 'i.consent_status'])->map(fn ($r) => (array) $r)->all() : [])
                ->columns(['default' => 1, 'md' => 5])
                ->schema(array_map(fn (string $c) => TextEntry::make($c)->label(__("workflow_actions.policy_context.columns.{$c}"))->placeholder('—'), ['scope', 'status', 'reason_code', 'item_status', 'consent_status'])),
        ]);

        return Tab::make(__('workflow_actions.policy_context.tab'))->schema($sections);
    }

    /** @param list<string> $columns */
    private static function rows(string $table, array $columns, string $order, Policy $policy): array
    {
        return DB::table($table)->where('policy_id', $policy->id)->where('tenant_id', $policy->tenant_id)
            ->orderByDesc($order)->limit(20)->get($columns)->map(fn ($r) => (array) $r)->all();
    }
}
