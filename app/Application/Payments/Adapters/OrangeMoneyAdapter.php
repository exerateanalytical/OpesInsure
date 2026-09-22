<?php

declare(strict_types=1);

namespace App\Application\Payments\Adapters;

use App\Models\PaymentIntentRecord;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Orange Money Web Payment API. order_id is set to our own payment_intents.id
 * so a callback (which Orange calls with order_id appended, alongside a
 * status we do not trust) can be resolved back to the intent without a
 * separate lookup table. provider_reference is the pay_token Orange returns,
 * which — together with order_id and amount, both already on the intent
 * record — is everything status() needs to re-query the authoritative state.
 */
final class OrangeMoneyAdapter implements PaymentProviderAdapter
{
    public function provider(): string
    {
        return 'orange_money';
    }

    public function requestCustomerAuthorization(PaymentIntentRecord $intent, string $requestId): ProviderInitiationResult
    {
        $baseUrl = $this->baseUrl();

        if ($baseUrl === '' || $this->config('merchant_key') === '' || $this->config('client_id') === '' || $this->config('client_secret') === '') {
            throw new DomainException('Orange Money provider is not configured.');
        }

        $country = (string) $this->config('country', 'cm');
        $notifUrl = route('payments.orange_money.callback', ['token' => $this->config('callback_token')]);

        $response = Http::baseUrl($baseUrl)
            ->withToken($this->accessToken())
            ->asJson()
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->post("/orange-money-webpay/{$country}/v1/webpayment", [
                'merchant_key' => $this->config('merchant_key'),
                'currency' => $intent->currency,
                'order_id' => $intent->id,
                'amount' => (string) $intent->amount_minor,
                'return_url' => $this->config('return_url'),
                'cancel_url' => $this->config('cancel_url'),
                'notif_url' => $notifUrl,
                'lang' => 'fr',
                'reference' => $requestId,
            ]);

        if (! $response->successful()) {
            throw new DomainException('Orange Money web payment initiation was rejected.');
        }

        $payToken = (string) $response->json('pay_token');

        if ($payToken === '') {
            throw new DomainException('Orange Money response did not include a pay_token.');
        }

        return new ProviderInitiationResult($payToken, 'PENDING_CUSTOMER', [
            'payment_url' => $response->json('payment_url'),
            'notif_token' => $response->json('notif_token'),
            'request_id' => $requestId,
        ]);
    }

    /**
     * The transactionstatus endpoint is the sole source of truth: a
     * notification's own "status" field must never be trusted directly (the
     * plan's "never trust a client-declared payment status" rule, extended
     * to provider push notifications for providers that expose a live
     * status-query API).
     *
     * @return array<string, mixed>
     */
    public function status(string $payToken, string $orderId, int $amountMinor): array
    {
        $country = (string) $this->config('country', 'cm');

        $response = Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->asJson()
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->post("/orange-money-webpay/{$country}/v1/transactionstatus", [
                'order_id' => $orderId,
                'amount' => (string) $amountMinor,
                'pay_token' => $payToken,
            ]);

        if (! $response->successful()) {
            throw new DomainException('Orange Money status query failed.');
        }

        return (array) $response->json();
    }

    private function accessToken(): string
    {
        $cacheKey = 'orange_money:access_token:'.$this->config('country', 'cm');

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl($this->baseUrl())
            ->asForm()
            ->withBasicAuth((string) $this->config('client_id'), (string) $this->config('client_secret'))
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->post('/oauth/v3/token', ['grant_type' => 'client_credentials']);

        if (! $response->successful()) {
            throw new DomainException('Orange Money authentication failed.');
        }

        $token = (string) $response->json('access_token');

        if ($token === '') {
            throw new DomainException('Orange Money authentication response did not include an access token.');
        }

        $expiresIn = (int) $response->json('expires_in', 3600);
        Cache::put($cacheKey, $token, max(60, $expiresIn - 60));

        return $token;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('payments.providers.orange_money.base_url'), '/');
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("payments.providers.orange_money.{$key}", $default);
    }
}
