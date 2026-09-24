<?php

declare(strict_types=1);

namespace App\Application\Notifications\Otp;

use App\Application\Settings\PlatformSettings;
use DomainException;
use Illuminate\Support\Facades\Http;

/**
 * ETECH KEYS WhatsApp Business: an approved template whose single body
 * parameter is the code. POST https://v1.api.etech-keys.com/api/v1/whatsapp/send
 */
final class EtechWhatsAppDriver implements OtpChannel
{
    public const URL = 'https://v1.api.etech-keys.com/api/v1/whatsapp/send';

    public function __construct(private PlatformSettings $settings)
    {
    }

    public function provider(): string
    {
        return 'etech';
    }

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function isConfigured(): bool
    {
        $c = $this->settings->etech();

        return $c['whatsapp_enabled'] && $c['rest_token'] !== '' && $c['whatsapp_template_name'] !== '';
    }

    public function send(string $phoneE164, string $code, string $message): string
    {
        $c = $this->settings->etech();

        $response = Http::withToken($c['rest_token'])->acceptJson()->timeout(15)->post(self::URL, [
            'to' => ltrim($phoneE164, '+'),
            'type' => 'template',
            'template' => [
                'name' => $c['whatsapp_template_name'],
                'language' => ['code' => $c['whatsapp_template_language'] ?: 'fr'],
                'components' => [
                    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                ],
            ],
        ]);

        if (! $response->successful()) {
            throw new DomainException('ETECH WhatsApp rejected the message (HTTP '.$response->status().').');
        }

        return EtechReference::from($response);
    }
}
