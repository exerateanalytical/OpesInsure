<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Audit\AuditWriter;
use App\Domain\Shared\StateMachine\TransitionDenied;
use App\Interfaces\Http\Errors\ApiProblemException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Base for the web workflow actions (claims, policy servicing, templates, approvals, work queues, health
 * pre-authorization / provider settlement). Every action:
 *  - is gated by the SAME permission the API route uses (RequirePermission middleware), hidden when not held and
 *    re-checked on execution (denial audited as `authorization.denied`, exactly like the middleware);
 *  - calls the SAME application service the API controller calls — no business logic lives here;
 *  - turns the service's refusals (ValidationException, ApiProblemException, TransitionDenied, abort()) into a
 *    visible danger notification and keeps the modal open, so a failure is never silent;
 *  - leaves auditing to the service (which already records it).
 *
 * Labels come from resources/lang/{en,fr}/workflow_actions.php.
 */
final class WorkflowAction
{
    /** $lang: the lang group holding "{name}.label" / ".help" / ".done" (one file per area, e.g. kyc_actions). */
    public static function make(string $name, ?string $permission, string $lang = 'workflow_actions'): Action
    {
        return Action::make($name)
            ->label(__("{$lang}.{$name}.label"))
            ->modalHeading(__("{$lang}.{$name}.label"))
            ->modalDescription(fn () => self::optional("{$lang}.{$name}.help"))
            ->modalSubmitActionLabel(__('workflow_actions.confirm'))
            ->authorize(fn (): bool => self::allowed($permission));
    }

    public static function allowed(?string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && ($permission === null || $user->hasPermission($permission));
    }

    /**
     * Runs the service call. Returns the service result, or null after notifying the failure (and halting the
     * action so its modal stays open with the user's input).
     */
    public static function run(Action $action, ?string $permission, callable $call, ?string $success = null): mixed
    {
        if (! self::allowed($permission)) {
            app(AuditWriter::class)->record('authorization.denied', 'filament_action', null, ['permission' => $permission, 'action' => $action->getName()], 'permission_denied');
            self::fail(__('workflow_actions.denied'));
            $action->halt();
        }

        try {
            $result = $call();
        } catch (ValidationException $e) {
            self::fail($e->validator->errors()->first());
            $action->halt();
        } catch (ApiProblemException|TransitionDenied $e) {
            self::fail($e->getMessage());
            $action->halt();
        } catch (HttpExceptionInterface $e) {
            self::fail($e->getMessage() ?: __('workflow_actions.denied'));
            $action->halt();
        }

        Notification::make()->success()->title($success ?? __("workflow_actions.{$action->getName()}.done"))->send();

        return $result;
    }

    public static function fail(string $message): void
    {
        Notification::make()->danger()->title(__('workflow_actions.failed'))->body($message)->send();
    }

    /** @param  array<int, string>  $values */
    public static function options(array $values, ?string $group = null): array
    {
        return collect($values)->mapWithKeys(fn ($v) => [$v => $group ? (self::optional("workflow_actions.codes.{$group}.{$v}") ?? $v) : $v])->all();
    }

    public static function id(mixed $record): string
    {
        return (string) (is_array($record) ? $record['id'] : $record->id);
    }

    public static function optional(string $key): ?string
    {
        $t = __($key);

        return $t === $key ? null : $t;
    }
}
