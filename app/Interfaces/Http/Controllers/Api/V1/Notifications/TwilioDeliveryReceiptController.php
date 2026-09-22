<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Notifications;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\NotificationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Twilio's StatusCallback (https://www.twilio.com/docs/usage/webhooks/sms-webhooks)
 * for both the SMS and WhatsApp adapters — same Messages resource, same
 * callback shape. Verified with Twilio's own request-signing scheme
 * (HMAC-SHA1 of the exact callback URL + sorted POST params, keyed by the
 * Auth Token we already hold) rather than a self-generated token, since
 * Twilio signs with a secret we're issued, unlike MTN/Orange.
 */
final class TwilioDeliveryReceiptController
{
    private const STATUS_MAP = [
        'delivered' => 'DELIVERED',
        'read' => 'DELIVERED',
        'failed' => 'FAILED',
        'undelivered' => 'FAILED',
    ];

    public function __invoke(Request $request, AuditWriter $audit, OutboxWriter $outbox): JsonResponse
    {
        $authToken = (string) config('services.twilio.auth_token');

        abort_unless($authToken !== '' && $this->hasValidSignature($request, $authToken), 401, 'Invalid Twilio signature.');

        $sid = (string) $request->input('MessageSid');
        $generic = self::STATUS_MAP[(string) $request->input('MessageStatus')] ?? null;

        if ($sid !== '' && $generic !== null) {
            $delivery = NotificationDelivery::where('provider_reference', $sid)->where('status', 'SENT')->first();

            if ($delivery) {
                $delivery->update([
                    'status' => $generic,
                    'delivered_at' => $generic === 'DELIVERED' ? now() : null,
                    'failure_code' => $generic === 'FAILED' ? (string) $request->input('ErrorCode') : null,
                ]);

                $audit->record('notification.'.mb_strtolower($generic), 'notification_delivery', $delivery->id, ['provider' => 'twilio']);
                $outbox->record('notification.delivery.'.mb_strtolower($generic), 'notification_delivery', $delivery->id, ['delivery_id' => $delivery->id]);
            }
        }

        return response()->json(['accepted' => true]);
    }

    private function hasValidSignature(Request $request, string $authToken): bool
    {
        $signature = (string) $request->header('X-Twilio-Signature');

        if ($signature === '') {
            return false;
        }

        $params = $request->all();
        ksort($params);

        $data = $request->fullUrl();

        foreach ($params as $key => $value) {
            $data .= $key.(is_array($value) ? implode('', $value) : (string) $value);
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($expected, $signature);
    }
}
