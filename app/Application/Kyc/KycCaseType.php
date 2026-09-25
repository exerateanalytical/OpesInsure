<?php

declare(strict_types=1);

namespace App\Application\Kyc;

/**
 * REQ-KYC-001 — the KYC_REVIEW case type (WRS WF-003) on the case engine (REQ-CAS-001).
 *
 * The WF-003 states map onto kyc_submissions.status (the customer-visible record) and this case's
 * status (the staff workflow). not_started = no submission, draft = DRAFT submission without a case;
 * the case opens on submit. EXPIRED is a post-approval state of the submission only (the case is closed).
 * approve / reject need a recorded case decision (checker) — CaseTypeCatalogue::DECISION_GUARD.
 */
final class KycCaseType
{
    public const CODE = 'KYC_REVIEW';

    /** @return list<array<string, mixed>> */
    public static function states(): array
    {
        return [
            ['code' => 'SUBMITTED', 'initial' => true],
            ['code' => 'REVIEWING'],
            ['code' => 'MORE_INFO_REQUIRED', 'pauses_sla' => true],
            ['code' => 'PENDING_APPROVAL'],
            ['code' => 'APPROVED', 'terminal' => true],
            ['code' => 'REJECTED', 'terminal' => true],
            ['code' => 'CANCELLED', 'terminal' => true],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function transitions(): array
    {
        return [
            ['event' => 'start_review', 'from' => ['SUBMITTED'], 'to' => 'REVIEWING', 'permission' => 'kyc.review'],
            ['event' => 'request_info', 'from' => ['SUBMITTED', 'REVIEWING', 'PENDING_APPROVAL'], 'to' => 'MORE_INFO_REQUIRED', 'requires_reason' => true, 'permission' => 'kyc.review'],
            ['event' => 'info_received', 'from' => ['MORE_INFO_REQUIRED'], 'to' => 'REVIEWING'],
            ['event' => 'recommend', 'from' => ['REVIEWING'], 'to' => 'PENDING_APPROVAL', 'permission' => 'kyc.review'],
            ['event' => 'return_to_review', 'from' => ['PENDING_APPROVAL'], 'to' => 'REVIEWING', 'requires_reason' => true, 'permission' => 'kyc.decide'],
            ['event' => 'approve', 'from' => ['PENDING_APPROVAL'], 'to' => 'APPROVED', 'requires_decision' => true, 'permission' => 'kyc.decide'],
            ['event' => 'reject', 'from' => ['PENDING_APPROVAL'], 'to' => 'REJECTED', 'requires_decision' => true, 'permission' => 'kyc.decide'],
            ['event' => 'cancel', 'from' => ['SUBMITTED', 'REVIEWING', 'MORE_INFO_REQUIRED', 'PENDING_APPROVAL'], 'to' => 'CANCELLED', 'requires_reason' => true],
        ];
    }
}
