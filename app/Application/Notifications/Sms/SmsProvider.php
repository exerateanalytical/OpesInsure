<?php

declare(strict_types=1);

namespace App\Application\Notifications\Sms;

use App\Models\SmsProviderConnection;

/** One SMS API. send() throws SmsDeliveryException on any refusal and returns the provider's message reference. */
interface SmsProvider
{
    public function key(): string;

    /** @return list<string> settings keys that must be filled */
    public function requiredSettings(): array;

    /** @return list<string> secret keys that must be filled */
    public function requiredSecrets(): array;

    public function send(SmsProviderConnection $connection, string $toE164, string $message): string;
}
