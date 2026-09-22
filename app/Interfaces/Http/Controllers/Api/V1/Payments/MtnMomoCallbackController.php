<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Payments;

use App\Application\Payments\Adapters\MtnMomoAdapter;
use App\Application\Payments\MobileMoneyStatusReconciler;
use App\Models\PaymentIntentRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MTN does not sign its callback, so the URL we registered as X-Callback-Url
 * carries our own callback_token (compared below) plus the reference_id we
 * generated at initiation. The callback body itself is never parsed for a
 * status — receiving this request is only a cue to re-query MTN's own
 * status endpoint via MtnMomoAdapter::status(), matching the "never trust a
 * pushed status" rule applied to Orange's notifications too.
 */
final class MtnMomoCallbackController
{
    public function __invoke(Request $request, MtnMomoAdapter $adapter, MobileMoneyStatusReconciler $reconciler): JsonResponse
    {
        $referenceId = (string) $request->query('reference_id');
        $token = (string) $request->query('token');
        $expected = (string) config('payments.providers.mtn_momo.callback_token');

        if ($referenceId === '' || $expected === '' || ! hash_equals($expected, $token)) {
            abort(401, __('wave4.invalid_callback_token'));
        }

        $intent = PaymentIntentRecord::where('provider', 'mtn_momo')
            ->where('provider_reference', $referenceId)
            ->first();

        if ($intent) {
            $reconciler->reconcileMtn($intent, $adapter->status($referenceId));
        }

        // Ack even for an unrecognised reference so MTN stops retrying delivery.
        return response()->json(['accepted' => true]);
    }
}
