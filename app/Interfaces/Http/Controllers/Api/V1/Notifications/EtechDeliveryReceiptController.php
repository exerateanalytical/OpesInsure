<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Notifications;

use App\Models\OtpDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ETECH KEYS delivery report (DLR): tel, etat (1 = delivered, 2 = failed),
 * id, date. Updates the matching otp_deliveries row. ETECH does not sign its
 * callbacks, so this only ever moves a row we created between SENT,
 * DELIVERED and FAILED; it cannot create data or reveal anything.
 */
final class EtechDeliveryReceiptController
{
    public function __invoke(Request $request): JsonResponse
    {
        $id = (string) $request->input('id', '');
        $state = (string) $request->input('etat', '');
        $status = match ($state) {
            '1' => 'DELIVERED',
            '2' => 'FAILED',
            default => null,
        };

        $matched = false;

        if ($id !== '' && $status !== null) {
            $delivery = OtpDelivery::where('provider', 'etech')->where('provider_reference', $id)->first();

            if ($delivery) {
                $delivery->update([
                    'status' => $status,
                    'delivered_at' => $status === 'DELIVERED' ? now() : null,
                    'receipt' => [
                        'etat' => $state,
                        'date' => mb_substr((string) $request->input('date', ''), 0, 40),
                        'tel_hash' => hash('sha256', (string) $request->input('tel', '')),
                    ],
                ]);
                $matched = true;
            }
        }

        return response()->json(['accepted' => true, 'matched' => $matched]);
    }
}
