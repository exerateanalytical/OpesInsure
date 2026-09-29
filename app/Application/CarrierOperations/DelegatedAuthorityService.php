<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations;

use App\Application\Audit\AuditWriter;
use App\Domain\CarrierOperations\AuthorityChecker;
use App\Domain\CarrierOperations\AuthorityDecision;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carrier delegated-authority agreements, shared by the API (CarrierOperationsController) and the staff desktop
 * (DelegatedAuthorityActions): create a DRAFT (records its maker), approve DRAFT -> ACTIVE (four-eyes: never by the
 * maker), and check a policy against an agreement (read-only, AuthorityChecker). Callers validate input.
 */
final class DelegatedAuthorityService
{
    public function __construct(private readonly AuditWriter $audit, private readonly AuthorityChecker $checker) {}

    /** @param array{carrier_id: string, partner_id: string, agreement_number: string, effective_from: string, effective_until: string, permitted_lines: list<string>, max_policy_premium_minor: int, max_claim_authority_minor: int, territories: list<string>} $d */
    public function create(array $d, User $actor): array
    {
        $id = (string) Str::uuid();
        DB::table('delegated_authority_agreements')->insert([...$d, 'id' => $id, 'status' => 'DRAFT', 'permitted_lines' => json_encode($d['permitted_lines']),
            'territories' => json_encode($d['territories']), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('delegated_authority.created', 'delegated_authority_agreement', $id, ['agreement_number' => $d['agreement_number']]);

        return ['id' => $id, 'status' => 'DRAFT'];
    }

    /** Only a DRAFT is approved (else 409); the maker of the agreement cannot approve it (403 MAKER_CHECKER_VIOLATION). */
    public function approve(string $agreementId, User $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($agreementId, $actor, $reason) {
            $row = DB::table('delegated_authority_agreements')->where('id', $agreementId)->lockForUpdate()->first();
            if ($row && $row->status === 'DRAFT' && $row->created_by !== null && $row->created_by === $actor->id) {
                throw new ApiProblemException('MAKER_CHECKER_VIOLATION', 403, 'The user who created the agreement cannot approve it.');
            }
            $updated = DB::table('delegated_authority_agreements')->where(['id' => $agreementId, 'status' => 'DRAFT'])
                ->update(['status' => 'ACTIVE', 'approved_by_carrier' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
            abort_unless($updated, 409);
            $this->audit->record('delegated_authority.approved', 'delegated_authority_agreement', $agreementId, [], $reason);

            return ['id' => $agreementId, 'status' => 'ACTIVE'];
        });
    }

    public function check(string $agreementId, string $line, int $premiumMinor, string $territory, \DateTimeImmutable $at): AuthorityDecision
    {
        $a = DB::table('delegated_authority_agreements')->where('id', $agreementId)->first();
        abort_unless($a, 404);

        return $this->checker->check(['status' => $a->status, 'effective_from' => $a->effective_from, 'effective_until' => $a->effective_until,
            'permitted_lines' => json_decode($a->permitted_lines, true), 'max_policy_premium_minor' => $a->max_policy_premium_minor,
            'territories' => json_decode($a->territories, true)], $line, $premiumMinor, $territory, $at);
    }
}
