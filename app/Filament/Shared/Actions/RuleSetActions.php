<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Rules\Models\RuleSet;
use App\Application\Rules\RuleEngine;
use App\Application\Rules\RuleSetService;
use App\Domain\Rules\Expression\ExpressionValidator;
use App\Domain\Rules\RuleSetDefinition;
use App\Filament\Shared\Actions\RegulatoryCrmSupport as S;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Validator;

/**
 * Rule set authoring and governance (REQ-RUL-002). Same service, validation and permission as RuleSetController.
 * Publication stays in RuleSetService: submit opens the approval request (rule_set.approve), approve/reject go through
 * the approval engine (maker ≠ checker, content-hash check), and only an APPROVED set can be retired.
 *   ruleSetCreate      POST rule-sets                        rules.manage   RuleSetService::createDraft
 *   ruleSetValidate    POST rule-sets/validate-expression    rules.manage   ExpressionValidator (inline, as the API)
 *   ruleSetSubmit      POST rule-sets/{s}/submit             rules.manage   RuleSetService::submit
 *   ruleSetApprove     POST rule-sets/{s}/approve            rules.approve  RuleSetService::decide(true)
 *   ruleSetReject      POST rule-sets/{s}/reject             rules.approve  RuleSetService::decide(false)
 *   ruleSetRetire      POST rule-sets/{s}/retire             rules.approve  RuleSetService::retire
 *   ruleSetSimulate    POST rule-sets/{s}/simulate           rules.view     RuleEngine::simulate (nothing persisted)
 */
final class RuleSetActions
{
    public static function ruleSetCreate(): Action
    {
        $p = 'rules.manage';

        return WorkflowAction::make('ruleSetCreate', $p, S::L)->icon('lucide-plus')
            ->schema([
                TextInput::make('code')->label(S::f('code'))->required()->regex('/^[A-Z][A-Z0-9_.-]{1,95}$/'),
                Select::make('domain')->label(S::f('domain'))->options(S::opts(RuleSetDefinition::DOMAINS, 'domain'))->required(),
                TextInput::make('insurance_product_id')->label(S::f('insurance_product_id'))->uuid(),
                TextInput::make('line_code')->label(S::f('line_code'))->maxLength(32),
                Select::make('operation')->label(S::f('operation'))->options(S::opts(RuleSetService::OPERATIONS)),
                DatePicker::make('effective_from')->label(S::f('effective_from'))->required(),
                DatePicker::make('effective_until')->label(S::f('effective_until')),
                Textarea::make('description')->label(S::f('description'))->maxLength(2000),
                S::jsonField('rules'),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $rules = S::json($data, 'rules');
                $data['rules'] = $rules;
                $d = S::check($data, [
                    'code' => 'required|string|regex:/^[A-Z][A-Z0-9_.-]{1,95}$/',
                    'domain' => 'required|string|in:'.implode(',', RuleSetDefinition::DOMAINS),
                    'insurance_product_id' => 'nullable|uuid|exists:insurance_products,id',
                    'line_code' => 'nullable|string|max:32',
                    'operation' => 'nullable|string|in:'.implode(',', RuleSetService::OPERATIONS),
                    'effective_from' => 'required|date',
                    'effective_until' => 'nullable|date|after_or_equal:effective_from',
                    'description' => 'nullable|string|max:2000',
                    'rules' => 'required|array|min:1|max:200',
                    'rules.*.code' => 'required|string|max:96',
                    'rules.*.condition' => 'present',
                    'rules.*.outcome' => 'required|array',
                ]);

                return app(RuleSetService::class)->createDraft(['rules' => $rules] + $d, S::user());
            }, S::done('ruleSetCreate')));
    }

    /** Pure check (the API returns {valid, errors}); the result is shown, nothing is stored. */
    public static function ruleSetValidate(): Action
    {
        $p = 'rules.manage';

        return WorkflowAction::make('ruleSetValidate', $p, S::L)->icon('lucide-spell-check')->color('gray')
            ->schema([S::jsonField('condition')])
            ->action(function (Action $action, array $data) use ($p) {
                $errors = WorkflowAction::run($action, $p, fn () => (new ExpressionValidator)->validate(S::json($data, 'condition')), S::done('ruleSetValidate'));
                if (is_array($errors)) {
                    $errors === []
                        ? Notification::make()->success()->title(__(S::L.'.ruleSetValidate.valid'))->send()
                        : Notification::make()->warning()->title(__(S::L.'.ruleSetValidate.invalid'))->body(implode("\n", array_map(fn ($e) => is_array($e) ? json_encode($e) : (string) $e, $errors)))->send();
                }
            });
    }

    public static function ruleSetSubmit(): Action
    {
        $p = 'rules.manage';

        return WorkflowAction::make('ruleSetSubmit', $p, S::L)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn (mixed $record) => S::field($record, 'status') === 'DRAFT')
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(RuleSetService::class)->submit(self::set($record), S::user()), S::done('ruleSetSubmit')));
    }

    public static function ruleSetApprove(): Action
    {
        $p = 'rules.approve';

        return WorkflowAction::make('ruleSetApprove', $p, S::L)->icon('lucide-badge-check')->color('success')
            ->visible(fn (mixed $record) => S::field($record, 'status') === 'IN_REVIEW')
            ->schema([Textarea::make('note')->label(S::f('note'))->maxLength(500)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['note' => 'nullable|string|max:500']);

                return app(RuleSetService::class)->decide(self::set($record), S::user(), true, $d['note'] ?? null);
            }, S::done('ruleSetApprove')));
    }

    public static function ruleSetReject(): Action
    {
        $p = 'rules.approve';

        return WorkflowAction::make('ruleSetReject', $p, S::L)->icon('lucide-circle-x')->color('danger')
            ->visible(fn (mixed $record) => S::field($record, 'status') === 'IN_REVIEW')
            ->schema([Textarea::make('note')->label(S::f('note'))->required()->minLength(3)->maxLength(500)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['note' => 'required|string|min:3|max:500']);

                return app(RuleSetService::class)->decide(self::set($record), S::user(), false, $d['note']);
            }, S::done('ruleSetReject')));
    }

    public static function ruleSetRetire(): Action
    {
        $p = 'rules.approve';

        return WorkflowAction::make('ruleSetRetire', $p, S::L)->icon('lucide-archive')->color('danger')
            ->visible(fn (mixed $record) => S::field($record, 'status') === 'APPROVED')
            ->schema([Textarea::make('reason')->label(S::f('reason'))->required()->minLength(3)->maxLength(500)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['reason' => 'required|string|min:3|max:500']);

                return app(RuleSetService::class)->retire(self::set($record), S::user(), $d['reason']);
            }, S::done('ruleSetRetire')));
    }

    public static function ruleSetSimulate(): Action
    {
        $p = 'rules.view';

        return WorkflowAction::make('ruleSetSimulate', $p, S::L)->icon('lucide-flask-conical')->color('gray')
            ->schema([S::jsonField('facts'), DatePicker::make('reference_date')->label(S::f('reference_date'))])
            ->action(function (Action $action, mixed $record, array $data) use ($p) {
                $result = WorkflowAction::run($action, $p, function () use ($record, $data) {
                    $in = ['facts' => S::json($data, 'facts')] + S::present(['reference_date' => $data['reference_date'] ?? null]);
                    $d = Validator::make($in, ['facts' => 'present|array', 'reference_date' => 'nullable|date'])->validate();

                    return app(RuleEngine::class)->simulate(RuleSet::with('rules')->findOrFail(WorkflowAction::id($record)), $d['facts'],
                        isset($d['reference_date']) ? new \DateTimeImmutable($d['reference_date']) : null);
                }, S::done('ruleSetSimulate'));
                if (is_array($result)) {
                    Notification::make()->info()->persistent()->title(__(S::L.'.ruleSetSimulate.result', ['outcome' => (string) ($result['outcome'] ?? '—')]))
                        ->body(mb_strimwidth(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '', 0, 2000, '…'))->send();
                }
            });
    }

    private static function set(mixed $record): RuleSet
    {
        return RuleSet::findOrFail(WorkflowAction::id($record));
    }
}
