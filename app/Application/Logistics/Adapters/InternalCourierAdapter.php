<?php

declare(strict_types=1);

namespace App\Application\Logistics\Adapters;

use App\Models\Courier;

/**
 * The only courier "connector" that exists today: every courier currently
 * on this platform is internal staff/vehicles in the couriers table, not an
 * external delivery partner with its own API — FulfilmentController's
 * existing assign/transition logic already works against that table
 * directly and is left untouched. This adapter answers the one operation
 * the plan requires that nothing implements yet: "availability query"
 * (checkAvailability), matching an ACTIVE courier's service_areas against
 * the delivery address's city.
 */
final class InternalCourierAdapter implements CourierConnectorAdapter
{
    public function courierType(): string
    {
        return 'INTERNAL';
    }

    public function checkAvailability(string $tenantId, array $deliveryAddress): array
    {
        $city = mb_strtolower(trim((string) ($deliveryAddress['city'] ?? '')));

        if ($city === '') {
            return [];
        }

        return Courier::where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->get()
            ->filter(function (Courier $courier) use ($city) {
                $areas = array_map(fn ($area) => mb_strtolower(trim((string) $area)), $courier->service_areas ?? []);

                return in_array($city, $areas, true);
            })
            ->map(fn (Courier $courier) => ['id' => $courier->id, 'name' => $courier->name])
            ->values()
            ->all();
    }
}
