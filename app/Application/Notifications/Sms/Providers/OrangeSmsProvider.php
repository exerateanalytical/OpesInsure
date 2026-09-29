<?php

declare(strict_types=1);

namespace App\Application\Notifications\Sms\Providers;

use App\Application\Notifications\Sms\SmsDeliveryException;
use App\Application\Notifications\Sms\SmsProvider;
use App\Models\SmsProviderConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Orange SMS API (developer.orange.com, "SMS Cameroon"). OAuth2 client credentials:
 * POST {base}/oauth/v3/token (Basic client_id:client_secret, grant_type=client_credentials), then
 * POST {base}/smsmessaging/v1/outbound/{urlencoded senderAddress}/requests with outboundSMSMessageRequest.
 * senderAddress is the "tel:+237…" number of the Orange contract; senderName is optional (must be whitelisted by Orange).
 */
final class OrangeSmsProvider implements SmsProvider
{
    public const BASE_URL = 'https://api.orange.com';

    public function key(): string
    {
        return 'orange';
    }

    public function requiredSettings(): array
    {
        return ['sender_address'];
    }

    public function requiredSecrets(): array
    {
        return ['client_id', 'client_secret'];
    }

    public static function tokenCacheKey(SmsProviderConnection $c): string
    {
        return 'sms:orange:token:'.$c->id;
    }

    public function send(SmsProviderConnection $connection, string $toE164, string $message): string
    {
        $base = rtrim((string) $connection->setting('base_url', self::BASE_URL), '/');
        $sender = (string) $connection->setting('sender_address');
        if (! str_starts_with($sender, 'tel:')) {
            $sender = 'tel:'.$sender;
        }

        $request = ['address' => 'tel:'.$toE164, 'senderAddress' => $sender, 'outboundSMSTextMessage' => ['message' => $message]];
        if (($name = (string) $connection->setting('sender_name', '')) !== '') {
            $request['senderName'] = $name;
        }

        $response = Http::withToken($this->token($connection, $base))->acceptJson()->timeout(15)
            ->post($base.'/smsmessaging/v1/outbound/'.rawurlencode($sender).'/requests', ['outboundSMSMessageRequest' => $request]);

        if ($response->status() === 401) {
            Cache::forget(self::tokenCacheKey($connection));
        }

        if (! $response->successful()) {
            $detail = (string) ($response->json('requestError.serviceException.text') ?? $response->json('requestError.policyException.text') ?? $response->json('message') ?? '');
            throw new SmsDeliveryException('Orange refused the SMS (HTTP '.$response->status().')'.($detail !== '' ? ': '.mb_substr($detail, 0, 120) : '.'));
        }

        $url = (string) $response->json('outboundSMSMessageRequest.resourceURL', '');

        return $url !== '' ? mb_substr((string) last(explode('/', $url)), 0, 190) : '';
    }

    private function token(SmsProviderConnection $c, string $base): string
    {
        $cached = Cache::get(self::tokenCacheKey($c));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->withBasicAuth($c->secret('client_id'), $c->secret('client_secret'))->acceptJson()->timeout(15)
            ->post($base.'/oauth/v3/token', ['grant_type' => 'client_credentials']);

        $token = (string) $response->json('access_token', '');
        if (! $response->successful() || $token === '') {
            throw new SmsDeliveryException('Orange rejected the client credentials (HTTP '.$response->status().').');
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 120);
        Cache::put(self::tokenCacheKey($c), $token, $ttl);

        return $token;
    }
}
