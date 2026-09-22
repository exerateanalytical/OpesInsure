<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * See ServiceValidation for why this exists. Applies to CreateRecord/
 * EditRecord pages, whose create()/save() call a service through
 * handleRecordCreation()/handleRecordUpdate().
 */
trait NotifiesServiceValidationErrors
{
    public function create(bool $another = false): void
    {
        try {
            parent::create($another);
        } catch (ValidationException $exception) {
            ServiceValidation::notify($exception);
        }
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (ValidationException $exception) {
            ServiceValidation::notify($exception);
        }
    }
}
