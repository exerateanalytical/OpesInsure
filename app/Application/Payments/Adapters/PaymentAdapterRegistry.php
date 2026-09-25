<?php
declare(strict_types=1);

namespace App\Application\Payments\Adapters;

use InvalidArgumentException;

/**
 * Resolves the provider adapter for a payment. The fake adapter is handed out
 * only when the caller says the payment belongs to a demo persona
 * ($demoPersona, see App\Application\Demo\DemoPersonas), or for provider=fake
 * in local/testing. Demo mode on its own no longer swaps every payment to the
 * fake adapter: a real user on a demo-mode server goes through the real
 * adapter for their provider (audit A2).
 */
final class PaymentAdapterRegistry
{
    public function for(string $p, bool $demoPersona = false): PaymentProviderAdapter
    {
        if ($demoPersona && config('demo.enabled')) {
            return new FakePaymentAdapter;
        }

        return match ($p) {
            'fake' => app()->environment('local', 'testing') ? new FakePaymentAdapter : throw new InvalidArgumentException('The fake payment provider is only available to demo accounts.'),
            'mtn_momo' => new MtnMomoAdapter,
            'orange_money' => new OrangeMoneyAdapter,
            'bank_transfer' => new BankTransferAdapter,
            'card_sandbox' => new SandboxCardCheckoutAdapter,
            'maviance', 'campay' => new ConfiguredJsonPaymentAdapter($p),
            default => throw new InvalidArgumentException('Unsupported payment provider.'),
        };
    }
}
