<?php

declare(strict_types=1);

namespace App\Application\Security\Alerts;

use App\Application\Notifications\CustomerNotifier;
use App\Application\Notifications\NotificationCatalog;
use App\Models\User;
use Throwable;

/**
 * Mobile audit B3: security alerts to the account holder, delivered through the one notifier (CustomerNotifier) and
 * therefore the user's notification_preferences channels (push / SMS; the inbox row always). Security alerts are not
 * a mutable category: they always reach the inbox. Every alert deep-links to /account/security. Never throws.
 */
final class SecurityAlerts
{
    public const PATH = '/account/security';

    /** Alert codes; the copy (EN/FR) is NotificationCatalog "security_alert_<code>" in resources/lang/{en,fr}/customer_notifications.php. */
    public const CODES = [
        'NEW_DEVICE', 'PASSWORD_CHANGED', 'IDENTITY_CHANGED', 'SIGNED_OUT_EVERYWHERE', 'REPEATED_FAILED_SIGN_INS',
        'INTEGRITY_FAILURE', 'PAYOUT_DESTINATION_CHANGED', 'ACCESS_SUSPENDED', 'REAUTH_REQUIRED',
    ];

    public function __construct(private readonly CustomerNotifier $notifier) {}

    public function send(User $user, string $code, ?string $tenantId = null): void
    {
        try {
            if (! in_array($code, self::CODES, true)) {
                throw new \InvalidArgumentException("Unknown security alert {$code}");
            }
            $this->notifier->toUser($user, $tenantId, 'SECURITY', ...NotificationCatalog::message('security_alert_'.strtolower($code)), severity: 'WARNING', path: self::PATH);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
