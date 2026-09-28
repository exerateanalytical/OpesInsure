<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use Filament\Notifications\Notification;

/**
 * Bound in place of Filament's Notification: titles and bodies written in English
 * ("Customer status updated") go through the same FR/EN layer as panel labels
 * (resources/lang/fr.json). Text without an entry, such as a service error message, is shown unchanged.
 */
class LocalizedNotification extends Notification
{
    public function getTitle(): ?string
    {
        $title = parent::getTitle();

        return is_string($title) && $title !== '' ? LocalizedResource::t($title) : $title;
    }

    public function getBody(): ?string
    {
        $body = parent::getBody();

        return is_string($body) && $body !== '' ? LocalizedResource::t($body) : $body;
    }
}
