<?php

declare(strict_types=1);

namespace App\Application\Notifications;

/**
 * Workflow Data Master v1 `notifications` section reconciled with the existing notification stack.
 *
 *  - Channels: EMAIL / SMS / WHATSAPP have adapters (NotificationAdapterRegistry), PUSH goes through
 *    Push\SendPushNotificationJob, IN_APP is the Filament database-notification inbox. No channel is added.
 *  - Delivery statuses: notification_deliveries.status (CHECK widened with READ and BOUNCED by migration
 *    2026_10_11_800001). SENDING and DEAD_LETTERED are platform-internal states that map onto the owner list.
 *  - Consent types: stored in consents.purpose (free text, unchanged). The owner's five types are the vocabulary.
 *  - notification_event_catalogue_status is PENDING_SOURCE (the canonical event schema stays the only event list).
 */
final class NotificationVocabulary
{
    public const CHANNELS = ['EMAIL', 'SMS', 'WHATSAPP', 'PUSH', 'IN_APP'];

    public const DELIVERY_STATUSES = ['QUEUED', 'SENT', 'DELIVERED', 'FAILED', 'BOUNCED', 'READ', 'CANCELLED'];

    /** Platform-internal delivery states => owner delivery status. */
    public const INTERNAL_STATUS_MAP = ['SENDING' => 'QUEUED', 'DEAD_LETTERED' => 'FAILED'];

    public const CONSENT_TYPES = ['MARKETING', 'SERVICE', 'TRANSACTIONAL', 'CLAIMS', 'HEALTH'];

    public static function deliveryStatus(string $stored): string
    {
        return self::INTERNAL_STATUS_MAP[$stored] ?? $stored;
    }
}
