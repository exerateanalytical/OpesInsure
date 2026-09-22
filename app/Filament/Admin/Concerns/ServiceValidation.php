<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Application services in this codebase throw ValidationException with bare
 * field keys (e.g. 'partner_id'), not the 'data.partner_id' path Livewire's
 * client-side error binding expects from a Filament form or action schema.
 * Livewire still catches the exception and returns it to the browser, but
 * since no input has a matching wire:model, nothing ever renders — the
 * create/action silently does nothing and the user has no idea why. This
 * converts that into a visible danger notification so the failure is never
 * silent, whether the caller is a CreateRecord page (via the
 * NotifiesServiceValidationErrors trait) or a table/header action closure.
 */
final class ServiceValidation
{
    public static function notify(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Action failed')
            ->body($exception->validator->errors()->first())
            ->send();
    }

    /**
     * Runs a service call from inside an Action::make()->action() closure,
     * converting a ValidationException into a visible notification instead
     * of letting it disappear. Returns null (and does not re-throw) on
     * failure, since there is no form context left to attach the error to.
     */
    public static function run(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            static::notify($exception);

            return null;
        }
    }
}
