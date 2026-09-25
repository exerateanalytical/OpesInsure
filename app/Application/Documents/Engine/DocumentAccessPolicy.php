<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Models\Document;
use App\Models\User;

/**
 * Security levels enforced on access.
 *  - The policyholder (own policy, checked by the caller) sees PUBLIC_VERIFIABLE,
 *    CUSTOMER_PRIVATE, MEDICAL_RESTRICTED (their own medical documents) and
 *    FINANCIAL_RESTRICTED (their own receipts); never INSURER_CONFIDENTIAL,
 *    INTERNAL or REGULATORY.
 *  - Staff need documents.read plus a level permission for restricted levels:
 *    documents.medical.read, documents.financial.read,
 *    documents.confidential.read (INSURER_CONFIDENTIAL + INTERNAL),
 *    documents.regulatory.read.
 */
final class DocumentAccessPolicy
{
    public const CUSTOMER_LEVELS = ['PUBLIC_VERIFIABLE', 'CUSTOMER_PRIVATE', 'MEDICAL_RESTRICTED', 'FINANCIAL_RESTRICTED'];

    public static function customerMay(Document $d): bool
    {
        if (! in_array($d->security_level ?? 'CUSTOMER_PRIVATE', self::CUSTOMER_LEVELS, true)) {
            return false;
        }
        // Canonical access profiles (A1 public/recipient, A2 customer + intermediary): a snapshot-issued
        // document whose profiles include neither is not customer-facing. Additive to the level check.
        $profiles = self::profiles($d);

        return $profiles === [] || array_intersect($profiles, ['A1', 'A2']) !== [];
    }

    public static function staffMay(User $user, Document $d): bool
    {
        $needed = match ($d->security_level ?? 'CUSTOMER_PRIVATE') {
            'MEDICAL_RESTRICTED' => 'documents.medical.read',
            'FINANCIAL_RESTRICTED' => 'documents.financial.read',
            'INSURER_CONFIDENTIAL', 'INTERNAL' => 'documents.confidential.read',
            'REGULATORY' => 'documents.regulatory.read',
            default => null,
        };
        if ($needed !== null && ! $user->hasPermission($needed)) {
            return false;
        }
        // A5 only (compliance / regulatory / admin restricted) additionally needs the regulatory permission.
        $profiles = self::profiles($d);

        return ! ($profiles !== [] && array_diff($profiles, ['A5']) === [] && ! $user->hasPermission('documents.regulatory.read'));
    }

    /** @return array<int, string> access profiles frozen on the issued document (A1..A5) */
    public static function profiles(Document $d): array
    {
        $raw = $d->getAttributes()['access_profiles'] ?? null;
        $p = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);

        return is_array($p) ? array_values(array_filter($p, 'is_string')) : [];
    }
}
