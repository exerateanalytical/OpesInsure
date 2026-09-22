<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Payments;

use App\Application\Payments\Adapters\OrangeMoneyAdapter;
use App\Application\Payments\MobileMoneyStatusReconciler;
use App\Models\PaymentIntentRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Orange calls notif_url with its own "status" appended to the query
 * string — that value is never read or trusted here (see
 * OrangeMoneyAdapter::status() docblock). order_id was set to our own
 * payment_intents.id at initiation, so it resolves straight back to the
 * intent without a separate reference table. Accepts GET or POST: Orange's
 * documented notification mechanism varies by market/integration, and since
 * nothing here trusts the request body anyway, supporting both is free.
 */
final class OrangeMoneyCallbackController
{
    public function __invoke(Request $request, OrangeMoneyAdapter $adapter, MobileMoneyStatusReconciler $reconciler): JsonResponse
    {
        $orderId = (string) $request->input('order_id');
        $token = (string) $request->input('token');
        $expected = (string) config('payments.providers.orange_money.callback_token');

        if ($orderId === '' || $expected === '' || ! hash_equals($expected, $token)) {
            abort(401, __('wave4.invalid_callback_token'));
        }

        $intent = PaymentIntentRecord::where('provider', 'orange_money')
            ->where('id', $orderId)
            ->first();

        if ($intent && $intent->provider_reference !== null) {
            $status = $adapter->status($intent->provider_reference, $orderId, (int) $intent->amount_minor);
            $reconciler->reconcileOrange($intent, $status);
        }

        return response()->json(['accepted' => true]);
    }
}
