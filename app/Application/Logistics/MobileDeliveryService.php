<?php

declare(strict_types=1);

namespace App\Application\Logistics;

use App\Application\Identity\PartyResolver;
use App\Domain\Logistics\FulfilmentStateMachine;
use App\Models\FulfilmentOrder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing delivery tracking/address-update/confirmation — separate
 * from FulfilmentController::transition(), which is staff/courier-facing
 * (permission:fulfilments.manage) and handles every transition type, not
 * just the two a customer should ever trigger themselves. Ownership is
 * resolved via policy.party_id (FulfilmentOrder has no party_id of its own).
 */
final class MobileDeliveryService
{
    public function __construct(private PartyResolver $parties)
    {
    }

    public function show(string $deliveryId, User $user, string $tenantId): FulfilmentOrder
    {
        return $this->owned($deliveryId, $user, $tenantId)->load('courier');
    }

    public function updateAddress(string $deliveryId, array $address, User $user, string $tenantId): FulfilmentOrder
    {
        $order = $this->owned($deliveryId, $user, $tenantId);

        // Once a courier is actively assigned/en route, redirecting the
        // address is a courier/staff-mediated change, not a self-service one.
        if (! in_array($order->status, ['CREATED', 'READY_FOR_PICKUP'], true)) {
            throw ValidationException::withMessages(['address' => __('wave12.delivery_address_locked')]);
        }

        $order->update(['delivery_address' => $address]);

        DB::table('fulfilment_events')->insert([
            'id' => (string) Str::uuid(),
            'fulfilment_order_id' => $order->id,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'event_type' => 'ADDRESS_UPDATED',
            'courier_id' => $order->courier_id,
            'actor_id' => $user->id,
            'evidence' => json_encode(['address' => $address]),
            'occurred_at' => now(),
        ]);

        return $order->refresh();
    }

    public function confirm(string $deliveryId, string $otp, User $user, string $tenantId): FulfilmentOrder
    {
        return DB::transaction(function () use ($deliveryId, $otp, $user, $tenantId) {
            $order = $this->owned($deliveryId, $user, $tenantId);

            (new FulfilmentStateMachine)->assert($order->status, 'DELIVERED');

            if (! Hash::check($otp, $order->delivery_otp_hash)) {
                throw ValidationException::withMessages(['otp' => __('wave12.delivery_otp_invalid')]);
            }

            $order->update([
                'status' => 'DELIVERED',
                'delivered_at' => now(),
                'proof_of_delivery' => ['confirmed_by' => 'CUSTOMER', 'confirmed_at' => now()->toIso8601String()],
            ]);

            DB::table('fulfilment_events')->insert([
                'id' => (string) Str::uuid(),
                'fulfilment_order_id' => $order->id,
                'from_status' => 'IN_TRANSIT',
                'to_status' => 'DELIVERED',
                'event_type' => 'CUSTOMER_CONFIRMED',
                'courier_id' => $order->courier_id,
                'actor_id' => $user->id,
                'evidence' => json_encode([]),
                'occurred_at' => now(),
            ]);

            return $order->refresh();
        });
    }

    private function owned(string $deliveryId, User $user, string $tenantId): FulfilmentOrder
    {
        $order = $this->ownedQuery($user, $tenantId)->find($deliveryId);

        if (! $order) {
            $exists = FulfilmentOrder::where('tenant_id', $tenantId)->where('id', $deliveryId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $order;
    }

    private function ownedQuery(User $user, string $tenantId)
    {
        $party = $this->parties->forUser($user);
        $query = FulfilmentOrder::where('tenant_id', $tenantId);

        if (! $party) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('policy', fn ($q) => $q->where('party_id', $party->id));
    }
}
