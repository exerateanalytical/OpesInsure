<?php

declare(strict_types=1);

namespace App\Application\Payments\Adapters;

use App\Models\PaymentProviderConnection;

/**
 * Where MTN MoMo credentials come from: the platform-wide (tenant_id NULL) ACTIVE mtn_momo
 * PaymentProviderConnection whose encrypted `secrets` hold subscription_key / api_user / api_key /
 * callback_token (a PRODUCTION connection wins over a SANDBOX one), falling back to
 * config('payments.providers.mtn_momo') (.env) when no such connection exists.
 *
 * SANDBOX (target_environment "sandbox") moves no real money, so it is only ever used for seeded demo
 * personas (see MtnMomoAdapter, MobileMoneyStatusReconciler, PaymentInitiationService): a real customer
 * never pays against it. Secret values are never logged or serialised from here.
 */
final class MtnMomoCredentials
{
    /** @param array<string, string> $values */
    private function __construct(private array $values) {}

    public static function resolve(): self
    {
        $config = (array) config('payments.providers.mtn_momo', []);
        $values = [
            'base_url' => (string) ($config['base_url'] ?? ''),
            'target_environment' => (string) ($config['target_environment'] ?? 'sandbox'),
            'subscription_key' => (string) ($config['subscription_key'] ?? ''),
            'api_user' => (string) ($config['api_user'] ?? ''),
            'api_key' => (string) ($config['api_key'] ?? ''),
            'callback_token' => (string) ($config['callback_token'] ?? ''),
        ];

        $connection = PaymentProviderConnection::where('provider', 'mtn_momo')->whereNull('tenant_id')->where('status', 'ACTIVE')
            ->whereNotNull('secrets')->orderByRaw("CASE WHEN environment = 'PRODUCTION' THEN 0 ELSE 1 END")->first();
        $secrets = $connection ? (array) $connection->secrets : [];
        if ($secrets !== []) {
            $sandbox = $connection->environment !== 'PRODUCTION';
            $values = [
                'base_url' => (string) ($secrets['base_url'] ?? ($sandbox ? 'https://sandbox.momodeveloper.mtn.com' : $values['base_url'])),
                'target_environment' => (string) ($secrets['target_environment'] ?? ($sandbox ? 'sandbox' : $values['target_environment'])),
                'subscription_key' => (string) ($secrets['subscription_key'] ?? ''),
                'api_user' => (string) ($secrets['api_user'] ?? ''),
                'api_key' => (string) ($secrets['api_key'] ?? ''),
                'callback_token' => (string) ($secrets['callback_token'] ?? $values['callback_token']),
            ];
        }
        $values['base_url'] = rtrim($values['base_url'], '/');

        return new self($values);
    }

    public function get(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    public function configured(): bool
    {
        return $this->get('base_url') !== '' && $this->get('subscription_key') !== '' && $this->get('api_user') !== '' && $this->get('api_key') !== '';
    }

    public function isSandbox(): bool
    {
        return strtolower($this->get('target_environment')) === 'sandbox';
    }

    /** MTN's sandbox only accepts EUR; production collects in the intent's own currency (XAF). */
    public function wireCurrency(string $intentCurrency): string
    {
        return $this->isSandbox() ? 'EUR' : $intentCurrency;
    }

    /** Changes whenever the credentials change, reveals nothing (token cache key). */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->get('base_url').'|'.$this->get('target_environment').'|'.$this->get('api_user').'|'.$this->get('api_key').'|'.$this->get('subscription_key')), 0, 16);
    }
}
