<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Rating\TariffGovernanceService;
use App\Domain\Rating\TariffLifecycle;
use App\Models\TariffVersion;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;

/**
 * Tariff version lifecycle (UI batch 24) on the tariff version detail page. Same permission + service + validation as
 * TariffController / RatingController:
 *   tariffSubmit    POST tariffs/{t}/submit    tariff.manage   TariffGovernanceService::submit   notes 10..2000
 *   tariffApprove   POST tariffs/{t}/approve   tariff.approve  TariffGovernanceService::approve  reason 20..2000 (maker ≠ checker, hash, overlap)
 *   tariffReject    POST tariffs/{t}/reject    tariff.approve  TariffGovernanceService::reject   notes 10..2000 (maker ≠ checker)
 *   tariffSchedule  POST tariffs/{t}/schedule  tariff.publish  TariffGovernanceService::schedule
 *   tariffActivate  POST tariffs/{t}/activate  tariff.publish  TariffGovernanceService::activate
 *   tariffExpire    POST tariffs/{t}/expire    tariff.publish  TariffGovernanceService::expire   (+ effective_until)
 * Visibility mirrors TariffLifecycle::TRANSITIONS; the service stays the authority.
 */
final class TariffActions
{
    private const L = 'operations_actions';

    public static function group(): ActionGroup
    {
        return ActionGroup::make([self::submit(), self::approve(), self::reject(), self::schedule(), self::activate(), self::expire()])
            ->label(__(self::L.'.tariff_group'))->icon('lucide-zap')->button();
    }

    public static function submit(): Action
    {
        return self::transition('tariffSubmit', 'submit', 'tariff.manage', 'lucide-send',
            fn (TariffVersion $t, array $d) => app(TariffGovernanceService::class)->submit($t, auth()->user(), $d['notes']));
    }

    public static function approve(): Action
    {
        $p = 'tariff.approve';

        return WorkflowAction::make('tariffApprove', $p, self::L)->icon('lucide-badge-check')
            ->visible(fn (TariffVersion $record) => self::can('approve', $record))
            ->schema([Textarea::make('reason')->label(__(self::L.'.fields.reason'))->required()->minLength(20)->maxLength(2000)])
            ->action(fn (Action $action, TariffVersion $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(TariffGovernanceService::class)->approve(TariffVersion::findOrFail($record->id), auth()->user(), $data['reason']), __(self::L.'.tariffApprove.done')));
    }

    public static function reject(): Action
    {
        return self::transition('tariffReject', 'reject', 'tariff.approve', 'lucide-x',
            fn (TariffVersion $t, array $d) => app(TariffGovernanceService::class)->reject($t, auth()->user(), $d['notes']));
    }

    public static function schedule(): Action
    {
        return self::transition('tariffSchedule', 'schedule', 'tariff.publish', 'lucide-calendar-clock',
            fn (TariffVersion $t, array $d) => app(TariffGovernanceService::class)->schedule($t, auth()->user(), $d['notes']));
    }

    public static function activate(): Action
    {
        return self::transition('tariffActivate', 'activate', 'tariff.publish', 'lucide-play',
            fn (TariffVersion $t, array $d) => app(TariffGovernanceService::class)->activate($t, auth()->user(), $d['notes']));
    }

    public static function expire(): Action
    {
        return self::transition('tariffExpire', 'expire', 'tariff.publish', 'lucide-calendar-x',
            fn (TariffVersion $t, array $d) => app(TariffGovernanceService::class)->expire($t, auth()->user(), $d['notes'], $d['effective_until'] ?? null),
            [DatePicker::make('effective_until')->label(__(self::L.'.fields.effective_until'))]);
    }

    private static function transition(string $name, string $event, string $p, string $icon, \Closure $call, array $extra = []): Action
    {
        return WorkflowAction::make($name, $p, self::L)->icon($icon)
            ->visible(fn (TariffVersion $record) => self::can($event, $record))
            ->schema([Textarea::make('notes')->label(__(self::L.'.fields.notes'))->required()->minLength(10)->maxLength(2000), ...$extra])
            ->action(function (Action $action, TariffVersion $record, array $data) use ($name, $p, $call) {
                $data['effective_until'] = isset($data['effective_until']) && $data['effective_until'] ? (string) \Illuminate\Support\Carbon::parse($data['effective_until'])->toDateString() : null;

                return WorkflowAction::run($action, $p, fn () => $call(TariffVersion::findOrFail($record->id), $data), __(self::L.".{$name}.done"));
            });
    }

    private static function can(string $event, TariffVersion $t): bool
    {
        return in_array($t->status, TariffLifecycle::TRANSITIONS[$event][0] ?? [], true);
    }
}
