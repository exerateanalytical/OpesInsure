<?php

declare(strict_types=1);

namespace App\Application\Claims;

use App\Application\Audit\AuditWriter;
use App\Models\ClaimInvolvedParty;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Customer-reported driver/passenger/third-party/witness records for one of
 * the customer's own claims — the "involved parties" capability named in
 * the mobile Claims Completion merge guide. Deliberately simple compared to
 * the platform's real Party/PartyContact identity graph: no dedup, no KYC,
 * just what a claimant can self-report about who else was involved.
 *
 * Enforces the merge guide's "validate party consent and minimize
 * third-party personal information" mandate directly: contact details for
 * anyone other than the claimant themselves require an explicit
 * consent_given=true on the same request, and the audit trail records that
 * a party was added without echoing the contact details into it.
 */
final class MobileClaimPartyService
{
    public function __construct(
        private MobileClaimService $claims,
        private AuditWriter $audit,
    ) {
    }

    public function list(string $claimId, User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        $claim = $this->claims->owned($claimId, $user, $tenantId);

        return ClaimInvolvedParty::where('claim_id', $claim->id)->orderBy('created_at')->paginate($perPage);
    }

    /**
     * @param  array{role: string, display_name: string, is_self?: bool, contact_phone?: ?string, contact_email?: ?string, consent_given?: bool, notes?: ?string}  $data
     */
    public function add(string $claimId, array $data, User $user, string $tenantId): ClaimInvolvedParty
    {
        $claim = $this->claims->owned($claimId, $user, $tenantId);

        if ($claim->status === 'CLOSED') {
            throw ValidationException::withMessages(['claim' => __('wave12.claim_closed_for_edits')]);
        }

        $isSelf = $data['is_self'] ?? false;
        $hasContactDetails = filled($data['contact_phone'] ?? null) || filled($data['contact_email'] ?? null);
        $consentGiven = $data['consent_given'] ?? false;

        if (! $isSelf && $hasContactDetails && ! $consentGiven) {
            throw ValidationException::withMessages(['consent_given' => __('wave12.claim_party_consent_required')]);
        }

        $party = ClaimInvolvedParty::create([
            'claim_id' => $claim->id,
            'role' => $data['role'],
            'display_name' => $data['display_name'],
            'is_self' => $isSelf,
            'contact_phone' => $data['contact_phone'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'consent_given' => $consentGiven,
            'notes' => $data['notes'] ?? null,
            'added_by' => $user->id,
        ]);

        $this->audit->record('claim.mobile.party_added', 'claim', $claim->id, ['role' => $party->role, 'is_self' => $party->is_self]);

        return $party;
    }
}
