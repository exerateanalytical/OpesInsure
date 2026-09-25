<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Engine\DocumentRegister;
use Illuminate\Support\Facades\DB;

/**
 * REQ-DOC-008: document origin (who produced it: INSURER…SYSTEM) is distinct from the issuer and from the
 * stage (lifecycle step it belongs to). Issued documents come from INSURER / BROKER / SYSTEM; everything
 * else is incoming evidence. Third-party evidence is kept in the canonical `documents` store (REQ-DUP-021)
 * and read through its own surface, view `third_party_evidence_documents`, never mixed into issued packs.
 */
final class DocumentOrigin
{
    /** Parties other than the insurer side and the customer (third-party evidence). */
    public const THIRD_PARTY = ['PROVIDER', 'GARAGE', 'ADJUSTER', 'SURVEYOR', 'AUTHORITY', 'BANK', 'REGULATOR', 'REINSURER'];

    public const STAGES = ['QUOTE', 'UNDERWRITING', 'KYC', 'RISK_ASSET', 'ISSUANCE', 'POLICY', 'ENDORSEMENT', 'RENEWAL', 'CANCELLATION', 'CLAIM', 'FINANCE', 'SERVICING', 'REINSURANCE', 'REGULATORY'];

    public static function isValid(string $origin): bool
    {
        return in_array($origin, DocumentRegister::ORIGINS, true);
    }

    public static function isIssued(string $origin): bool
    {
        return in_array($origin, DocumentRegister::ISSUED_ORIGINS, true);
    }

    public static function isThirdPartyEvidence(string $origin): bool
    {
        return in_array($origin, self::THIRD_PARTY, true);
    }

    /** @return list<object> */
    public static function thirdPartyEvidence(string $tenantId, ?string $claimId = null, ?string $policyId = null): array
    {
        return DB::table('third_party_evidence_documents')->where('tenant_id', $tenantId)
            ->whereIn('document_origin', self::THIRD_PARTY)
            ->when($claimId, fn ($q) => $q->where('claim_id', $claimId))
            ->when($policyId, fn ($q) => $q->where('policy_id', $policyId))
            ->orderByDesc('created_at')->get()->all();
    }
}
