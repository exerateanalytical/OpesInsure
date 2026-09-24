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
        return in_array($d->security_level ?? 'CUSTOMER_PRIVATE', self::CUSTOMER_LEVELS, true);
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

        return $needed === null || $user->hasPermission($needed);
    }
}
