<?php

declare(strict_types=1);

namespace App\Application\Logistics\Adapters;

/**
 * Mirrors PaymentProviderAdapter/NotificationChannelAdapter: one adapter per
 * courier connection. Only InternalCourierAdapter exists today (see its
 * docblock) — this interface exists so a real external delivery partner's
 * API can be added later without touching FulfilmentController's existing,
 * working transition logic.
 */
interface CourierConnectorAdapter
{
    public function courierType(): string;

    /**
     * @param  array<string, mixed>  $deliveryAddress
     * @return array<int, array{id: string, name: string}>
     */
    public function checkAvailability(string $tenantId, array $deliveryAddress): array;
}
