<?php

declare(strict_types=1);

namespace App\Application\Security\Alerts;

use App\Application\Notifications\CustomerNotifier;
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

    /** code => [title, body] */
    public const MESSAGES = [
        'NEW_DEVICE' => ['New device signed in', 'Your account was just used on a new device. If this was not you, sign out everywhere and change your password.'],
        'PASSWORD_CHANGED' => ['Password changed', 'Your password was changed. If this was not you, contact support right away.'],
        'IDENTITY_CHANGED' => ['Account details changed', 'Your sign-in or contact details were changed. If this was not you, contact support right away.'],
        'SIGNED_OUT_EVERYWHERE' => ['Signed out everywhere', 'All sessions on all devices were signed out.'],
        'REPEATED_FAILED_SIGN_INS' => ['Failed sign-in attempts', 'Several failed sign-in attempts were made on your account.'],
        'INTEGRITY_FAILURE' => ['Device integrity check failed', 'A device using your account failed the integrity check. Sensitive actions are limited on it.'],
        'PAYOUT_DESTINATION_CHANGED' => ['Payout destination changed', 'The account your payouts are sent to was changed. If this was not you, contact support right away.'],
        'ACCESS_SUSPENDED' => ['Access suspended', 'Your organisation suspended your access.'],
        'REAUTH_REQUIRED' => ['Sign in again', 'Your organisation asked you to sign in again on every device.'],
    ];

    public function __construct(private readonly CustomerNotifier $notifier) {}

    public function send(User $user, string $code, ?string $tenantId = null): void
    {
        try {
            [$title, $body] = self::MESSAGES[$code];
            $this->notifier->toUser($user, $tenantId, 'SECURITY', $title, $body, 'WARNING', self::PATH);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
