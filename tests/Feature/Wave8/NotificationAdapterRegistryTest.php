<?php

declare(strict_types=1);

use App\Application\Notifications\Adapters\NotificationAdapterRegistry;
use App\Application\Notifications\Adapters\SmtpEmailAdapter;
use App\Application\Notifications\Adapters\TwilioSmsAdapter;
use App\Application\Notifications\Adapters\TwilioWhatsAppAdapter;

it('routes each channel to its dedicated adapter', function () {
    $registry = new NotificationAdapterRegistry;

    expect($registry->for('SMS'))->toBeInstanceOf(TwilioSmsAdapter::class);
    expect($registry->for('WHATSAPP'))->toBeInstanceOf(TwilioWhatsAppAdapter::class);
    expect($registry->for('EMAIL'))->toBeInstanceOf(SmtpEmailAdapter::class);
});

it('rejects an unsupported channel', function () {
    expect(fn () => (new NotificationAdapterRegistry)->for('PUSH'))->toThrow(InvalidArgumentException::class);
});
