<?php

declare(strict_types=1);

namespace App\Application\Payments\Adapters;

use App\Models\PaymentIntentRecord;
use DomainException;

/**
 * REQ-PAY-003 / WF-024 bank transfer. No provider API: initiation issues a deterministic payment reference and the
 * bank instructions, and the intent waits in AWAITING_TRANSFER (blueprint PENDING). It only becomes SUCCEEDED through a
 * signed bank-statement callback (webhooks/payments/bank_transfer, HMAC like the other providers) or statement
 * reconciliation matching that reference, amount and currency — never through anything the payer submits.
 */
final class BankTransferAdapter implements PaymentProviderAdapter
{
    public function provider(): string
    {
        return 'bank_transfer';
    }

    public static function referenceFor(PaymentIntentRecord $intent): string
    {
        return 'BT-'.strtoupper(substr(hash('sha256', 'bank_transfer|'.$intent->id), 0, 12));
    }

    public function requestCustomerAuthorization(PaymentIntentRecord $intent, string $requestId): ProviderInitiationResult
    {
        $c = (array) config('payments.providers.bank_transfer', []);
        if (($c['account_number'] ?? '') === '' || ($c['bank_name'] ?? '') === '') {
            throw new DomainException('Bank transfer account is not configured.');
        }
        $reference = self::referenceFor($intent);

        return new ProviderInitiationResult($reference, 'AWAITING_TRANSFER', [
            'request_id' => $requestId,
            'instructions' => [
                'reference' => $reference,
                'bank_name' => $c['bank_name'],
                'account_name' => $c['account_name'] ?? null,
                'account_number' => $c['account_number'],
                'swift' => $c['swift'] ?? null,
                'amount_minor' => (int) $intent->amount_minor,
                'currency' => $intent->currency,
                'pay_by' => now()->addDays((int) ($c['validity_days'] ?? 7))->toDateString(),
            ],
        ]);
    }
}
