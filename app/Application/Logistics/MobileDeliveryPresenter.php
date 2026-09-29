<?php

declare(strict_types=1);

namespace App\Application\Logistics;

use App\Models\FulfilmentOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The customer's view of one sticker/certificate delivery (GET, PUT address
 * and POST confirm under /mobile/deliveries/{id}). A raw FulfilmentOrder is
 * a staff record (OTP hash, idempotency key, free-form delivery_address), so
 * this flattens it into what the app renders: status, courier, tracking code,
 * the address fields, a timeline built from the real fulfilment_events and
 * the `version` the client echoes back on an address change
 * (EnforcesOptimisticConcurrency compares it against updated_at).
 */
final class MobileDeliveryPresenter
{
    /** The normal path a delivery walks; the timeline shows these in order. */
    private const PATH = ['CREATED', 'READY_FOR_PICKUP', 'ASSIGNED', 'PICKED_UP', 'IN_TRANSIT', 'DELIVERED'];

    /** Statuses that end or divert the normal path. */
    private const EXCEPTIONS = ['FAILED_ATTEMPT', 'RETURNING', 'RETURNED', 'CANCELLED'];

    /** Only while nobody is carrying the parcel can the customer redirect it. */
    public const ADDRESS_EDITABLE = ['CREATED', 'READY_FOR_PICKUP'];

    /** @return array<string, mixed> */
    public function present(FulfilmentOrder $order): array
    {
        $order->loadMissing('courier');
        $address = is_array($order->delivery_address) ? $order->delivery_address : [];
        $events = $this->events($order->id);
        $version = $order->updated_at?->toIso8601String();

        return [
            'id' => $order->id,
            'policy_id' => $order->policy_id,
            'status' => $order->status,
            'tracking_code' => $order->tracking_number,
            'courier' => $order->courier ? [
                'name' => $order->courier->name,
                'phone_e164' => $order->courier->phone_e164,
            ] : null,
            'recipient_name' => $this->pick($address, ['recipient_name', 'name']),
            'phone_e164' => $this->pick($address, ['phone_e164', 'phone']),
            'address_line' => $this->pick($address, ['address_line', 'line1', 'street']),
            'city' => $this->pick($address, ['city']),
            'region' => $this->pick($address, ['region']),
            'delivery_address' => $address,
            'eta' => $order->sla_due_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'delivery_attempts' => (int) ($order->delivery_attempts ?? 0),
            'can_change_address' => in_array($order->status, self::ADDRESS_EDITABLE, true),
            'can_confirm' => $order->status === 'IN_TRANSIT',
            'timeline' => $this->timeline($order, $events),
            'version' => $version,
            'updated_at' => $version,
        ];
    }

    /**
     * One step per normal-path status (complete once reached, with the time
     * it was first reached), followed by any exception status the order has
     * actually been through — never an invented step.
     *
     * @param  list<object{to_status: string, occurred_at: string}>  $events
     * @return list<array{status: string, occurred_at: ?string, complete: bool}>
     */
    private function timeline(FulfilmentOrder $order, array $events): array
    {
        $reachedAt = ['CREATED' => $order->created_at?->toIso8601String()];
        foreach ($events as $event) {
            $reachedAt[$event->to_status] ??= Carbon::parse($event->occurred_at)->toIso8601String();
        }

        $index = array_search($order->status, self::PATH, true);
        $furthest = $index === false ? -1 : $index;
        foreach (self::PATH as $i => $status) {
            if (isset($reachedAt[$status]) && $i > $furthest) {
                $furthest = $i;
            }
        }

        $steps = [];
        foreach (self::PATH as $i => $status) {
            $steps[] = ['status' => $status, 'occurred_at' => $reachedAt[$status] ?? null, 'complete' => $i <= $furthest];
        }

        foreach (self::EXCEPTIONS as $status) {
            if (isset($reachedAt[$status]) || $order->status === $status) {
                $steps[] = ['status' => $status, 'occurred_at' => $reachedAt[$status] ?? null, 'complete' => true];
            }
        }

        return $steps;
    }

    /** @return list<object> */
    private function events(string $orderId): array
    {
        return DB::table('fulfilment_events')
            ->where('fulfilment_order_id', $orderId)
            ->orderBy('occurred_at')
            ->get(['to_status', 'occurred_at'])
            ->all();
    }

    /** @param  array<string, mixed>  $address */
    private function pick(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $address[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
