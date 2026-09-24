<?php

declare(strict_types=1);

namespace App\Application\Notifications\Otp;

use App\Application\Settings\PlatformSettings;
use DomainException;
use Illuminate\Support\Facades\Http;

/**
 * ETECH KEYS SMS. Prefers the REST v1 API when a bearer token is set
 * (POST https://v1.api.etech-keys.com/api/v1/send-sms {sender, tel, msg}),
 * otherwise the legacy GET https://sms.etech-keys.com/ss/envoyer.php
 * (login, password, sender, tel, msg).
 */
final class EtechSmsDriver implements OtpChannel
{
    public const REST_URL = 'https://v1.api.etech-keys.com/api/v1/send-sms';

    public const LEGACY_URL = 'https://sms.etech-keys.com/ss/envoyer.php';

    public function __construct(private PlatformSettings $settings)
    {
    }

    public function provider(): string
    {
        return 'etech';
    }

    public function channel(): string
    {
        return 'sms';
    }

    public function isConfigured(): bool
    {
        $c = $this->settings->etech();

        return $c['sms_enabled'] && $c['sms_sender'] !== ''
            && ($c['rest_token'] !== '' || ($c['sms_login'] !== '' && $c['sms_password'] !== ''));
    }

    public function send(string $phoneE164, string $code, string $message): string
    {
        $c = $this->settings->etech();
        $tel = ltrim($phoneE164, '+');

        if ($c['rest_token'] !== '') {
            $response = Http::withToken($c['rest_token'])->acceptJson()->timeout(15)
                ->post(self::REST_URL, ['sender' => $c['sms_sender'], 'tel' => $tel, 'msg' => $message]);
        } else {
            $response = Http::timeout(15)->get(self::LEGACY_URL, [
                'login' => $c['sms_login'],
                'password' => $c['sms_password'],
                'sender' => $c['sms_sender'],
                'tel' => $tel,
                'msg' => $message,
            ]);
        }

        if (! $response->successful()) {
            throw new DomainException('ETECH SMS rejected the message (HTTP '.$response->status().').');
        }

        return EtechReference::from($response);
    }
}
