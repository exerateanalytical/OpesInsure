<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Attribution\PortfolioTransferService;
use App\Application\PartnerWorkspace\LeadDirectoryService;
use App\Application\PartnerWorkspace\LeadPipeline;
use App\Filament\Shared\Actions\RegulatoryCrmSupport as S;
use App\Models\Partner;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\Validator;

/**
 * CRM lead directory and portfolio transfer (REQ-CRM-001, attribution). Same service, validation and permission as the API:
 *   leadCreate         POST crm/leads                         crm.leads.manage     LeadDirectoryService::create
 *   leadActivity       POST crm/leads/{lead}/activities       crm.leads.manage     LeadDirectoryService::find + addActivity
 *   leadAssign         POST crm/leads/{lead}/assign           crm.leads.assign     LeadDirectoryService::assign
 *   leadTransition     POST crm/leads/{lead}/transitions      crm.leads.manage     LeadDirectoryService::transition
 *   portfolioTransfer  POST crm/portfolio-transfers           attribution.transfer PortfolioTransferService::preview → execute (preview hash guard kept)
 */
final class CrmLeadActions
{
    public static function leadCreate(): Action
    {
        $p = 'crm.leads.manage';

        return WorkflowAction::make('leadCreate', $p, S::L)->icon('lucide-user-plus')
            ->schema([
                TextInput::make('full_name')->label(S::f('full_name'))->required()->maxLength(160),
                TextInput::make('phone_e164')->label(S::f('phone_e164'))->required()->regex('/^\+[1-9]\d{6,14}$/'),
                TextInput::make('city')->label(S::f('city'))->maxLength(80),
                TextInput::make('product_interest')->label(S::f('product_interest'))->maxLength(32),
                TextInput::make('source')->label(S::f('source'))->maxLength(32),
                Textarea::make('notes')->label(S::f('notes'))->maxLength(2000),
                self::partner('partner_id'),
                S::member('assigned_user_id'),
                Toggle::make('auto_assign')->label(S::f('auto_assign')),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $in = S::present($data);
                $in['auto_assign'] = (bool) ($data['auto_assign'] ?? false);
                $d = S::check($in, [
                    'full_name' => 'required|string|max:160', 'phone_e164' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
                    'city' => 'nullable|string|max:80', 'product_interest' => 'nullable|string|max:32', 'notes' => 'nullable|string|max:2000',
                    'source' => 'nullable|string|max:32', 'partner_id' => 'nullable|uuid', 'assigned_user_id' => 'nullable|uuid', 'auto_assign' => 'sometimes|boolean',
                ]);

                return app(LeadDirectoryService::class)->create($d, S::user(), S::tenant());
            }, S::done('leadCreate')));
    }

    public static function leadActivity(): Action
    {
        $p = 'crm.leads.manage';

        return WorkflowAction::make('leadActivity', $p, S::L)->icon('lucide-message-square-plus')
            ->schema([
                Select::make('entry_type')->label(S::f('entry_type'))->options(S::opts(LeadDirectoryService::ACTIVITY_TYPES, 'entry_type'))->required(),
                Textarea::make('body')->label(S::f('body'))->required()->maxLength(4000),
                DateTimePicker::make('follow_up_at')->label(S::f('follow_up_at')),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['entry_type' => 'required|in:'.implode(',', LeadDirectoryService::ACTIVITY_TYPES), 'body' => 'required|string|max:4000', 'follow_up_at' => 'nullable|date|after:now']);
                $leads = app(LeadDirectoryService::class);

                return $leads->addActivity($leads->find(WorkflowAction::id($record), S::user(), S::tenant()), $d, S::user());
            }, S::done('leadActivity')));
    }

    public static function leadAssign(): Action
    {
        $p = 'crm.leads.assign';

        return WorkflowAction::make('leadAssign', $p, S::L)->icon('lucide-user-cog')
            ->visible(fn (mixed $record) => LeadPipeline::isOpen((string) S::field($record, 'status')))
            ->schema([
                self::partner('partner_id'),
                S::member('assigned_user_id'),
                TextInput::make('reason')->label(S::f('reason'))->required()->maxLength(255),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                // partner_id is "present" (null = unassign the firm); assigned_user_id is sent only when chosen.
                $in = ['partner_id' => filled($data['partner_id'] ?? null) ? $data['partner_id'] : null, 'reason' => $data['reason'] ?? null]
                    + (filled($data['assigned_user_id'] ?? null) ? ['assigned_user_id' => $data['assigned_user_id']] : []);
                $d = Validator::make($in, ['partner_id' => 'present|nullable|uuid', 'assigned_user_id' => 'sometimes|nullable|uuid', 'reason' => 'required|string|max:255'])->validate();

                return app(LeadDirectoryService::class)->assign(WorkflowAction::id($record), $d, S::user(), S::tenant());
            }, S::done('leadAssign')));
    }

    public static function leadTransition(): Action
    {
        $p = 'crm.leads.manage';

        return WorkflowAction::make('leadTransition', $p, S::L)->icon('lucide-arrow-right-circle')
            ->visible(fn (mixed $record) => LeadPipeline::isOpen((string) S::field($record, 'status')))
            ->schema([
                Select::make('status')->label(S::f('lead_status'))->required()
                    ->options(fn (mixed $record) => S::opts(array_values(array_intersect(LeadPipeline::next((string) S::field($record, 'status')), LeadPipeline::MANUAL_STATUSES)), 'lead_status')),
                TextInput::make('lost_reason')->label(S::f('lost_reason'))->maxLength(255),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['status' => 'required|in:'.implode(',', LeadPipeline::MANUAL_STATUSES), 'lost_reason' => 'nullable|string|max:255']);

                return app(LeadDirectoryService::class)->transition(WorkflowAction::id($record), $d['status'], $d['lost_reason'] ?? null, S::user(), S::tenant());
            }, S::done('leadTransition')));
    }

    /**
     * Choosing both partners runs PortfolioTransferService::preview (POST crm/portfolio-transfers/preview) and keeps its
     * preview_hash; execute() refuses if the book changed since, exactly as the API two-step flow.
     */
    public static function portfolioTransfer(): Action
    {
        $p = 'attribution.transfer';
        $refresh = function ($get, $set): void {
            $from = $get('from_partner_id');
            $to = $get('to_partner_id');
            $preview = filled($from) && filled($to) ? rescue(fn () => app(PortfolioTransferService::class)->preview(S::tenant(), $from, $to, null), null, false) : null;
            $set('preview_hash', $preview['preview_hash'] ?? null);
            $set('preview_summary', $preview ? __(S::L.'.portfolioTransfer.summary', ['customers' => $preview['customer_count'], 'policies' => $preview['policy_count']]) : null);
        };

        return WorkflowAction::make('portfolioTransfer', $p, S::L)->icon('lucide-arrow-left-right')
            ->schema([
                self::partner('from_partner_id')->required()->live()->afterStateUpdated($refresh),
                self::partner('to_partner_id')->required()->live()->afterStateUpdated($refresh),
                TextInput::make('preview_summary')->label(S::f('preview_summary'))->readOnly()->dehydrated(false),
                TextInput::make('preview_hash')->label(S::f('preview_hash'))->readOnly()->required(),
                Select::make('reason_code')->label(S::f('reason_code'))->options(S::opts(PortfolioTransferService::REASONS, 'reason_code'))->required(),
                Textarea::make('notes')->label(S::f('notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $d = S::check($data, ['from_partner_id' => 'required|uuid', 'to_partner_id' => 'required|uuid', 'preview_hash' => 'required|string|size:64',
                    'reason_code' => 'required|in:'.implode(',', PortfolioTransferService::REASONS), 'notes' => 'nullable|string|max:2000']);

                return app(PortfolioTransferService::class)->execute(S::tenant(), $d['from_partner_id'], $d['to_partner_id'], null, $d['preview_hash'], $d['reason_code'], $d['notes'] ?? null, S::user());
            }, S::done('portfolioTransfer')));
    }

    private static function partner(string $name): Select
    {
        return Select::make($name)->label(S::f($name))->searchable()
            ->options(fn () => Partner::with('party')->where('tenant_id', S::tenant())->limit(500)->get()->mapWithKeys(fn ($p) => [$p->id => $p->party?->display_name ?? $p->legal_name ?? $p->id])->all());
    }
}
