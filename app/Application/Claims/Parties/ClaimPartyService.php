<?php

declare(strict_types=1);

namespace App\Application\Claims\Parties;

use App\Application\Audit\AuditWriter;
use App\Application\Customers\Matching\PartyMatcher;
use App\Application\Customers\Matching\PartyMergeService;
use App\Application\Customers\PartyService;
use App\Application\Events\OutboxWriter;
use App\Models\Claim;
use App\Models\ClaimInvolvedParty;
use App\Models\Partner;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-006 — staff management of the generic claim party (table claim_involved_parties, shared with the mobile
 * self-reported parties). Every add/update/remove is dated, audited and emits an outbox event.
 *
 * Golden-record linking (Phase 4 parties), in order:
 *  1. party_id given → linked to the live record (merge redirects followed) — LINKED;
 *  2. partner_id given (provider network: garage, health provider, adjuster, expert…) → the partner's party — LINKED;
 *  3. link_party=true → exact contact match (phone/email already on a golden record) — MATCHED; otherwise a new party
 *     is created (CREATED) and scanned by PartyMatcher: probable duplicates become steward candidates for a
 *     human-approved merge (REVIEW_PENDING). Nothing is ever merged silently here.
 * Bank details (payees) are stored encrypted and only exposed masked; third-party contact/bank data needs a consent basis.
 */
final class ClaimPartyService
{
    public const ROLES = ['CLAIMANT', 'INSURED', 'POLICYHOLDER', 'THIRD_PARTY', 'DRIVER', 'PASSENGER', 'WITNESS', 'INJURED', 'DECEASED',
        'BENEFICIARY', 'PAYEE', 'LAWYER', 'REPAIRER', 'EXPERT', 'ADJUSTER', 'SURVEYOR', 'HEALTH_PROVIDER', 'THIRD_PARTY_INSURER', 'AUTHORITY', 'OTHER'];

    public const CONSENT_BASES = ['EXPLICIT', 'CONTRACT', 'LEGAL_OBLIGATION', 'LEGITIMATE_INTEREST'];

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly PartyService $parties,
        private readonly PartyMatcher $matcher,
        private readonly PartyMergeService $merges,
    ) {}

    /** @return Collection<int, ClaimInvolvedParty> */
    public function list(Claim $claim, bool $includeRemoved = false): Collection
    {
        return ClaimInvolvedParty::where('claim_id', $claim->id)->when(! $includeRemoved, fn ($q) => $q->active())
            ->with('party:id,type,display_name,status,merged_into_id')->orderBy('created_at')->get();
    }

    /** @param array<string, mixed> $data */
    public function add(Claim $claim, array $data, User $actor): ClaimInvolvedParty
    {
        $this->assertOpen($claim);

        return DB::transaction(function () use ($claim, $data, $actor) {
            [$partyId, $partnerId, $match, $candidates] = $this->link($claim, $data, $actor);
            $this->assertConsent($data);
            $this->assertSingleClaimant($claim, $data['role'], $partyId);
            $row = ClaimInvolvedParty::create(array_merge($this->attributes($data), [
                'tenant_id' => $claim->tenant_id, 'claim_id' => $claim->id, 'party_id' => $partyId, 'partner_id' => $partnerId,
                'role' => $data['role'], 'display_name' => $data['display_name'] ?? $this->nameFor($partyId),
                'is_self' => $partyId !== null && $partyId === $claim->claimant_party_id,
                'source' => 'STAFF', 'match_status' => $match, 'match_candidates' => $candidates,
                'effective_from' => $data['effective_from'] ?? now()->toDateString(), 'added_by' => $actor->id,
            ]));
            $this->audit->record('claim.party.added', 'claim', $claim->id, ['claim_party_id' => $row->id, 'role' => $row->role,
                'party_id' => $partyId, 'partner_id' => $partnerId, 'match_status' => $match, 'has_bank_details' => $row->bank_account_masked !== null]);
            $this->outbox->record('claim.party.added', 'claim', $claim->id, ['claim_party_id' => $row->id, 'role' => $row->role, 'party_id' => $partyId]);

            return $row->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ClaimInvolvedParty $row, array $data, User $actor): ClaimInvolvedParty
    {
        if ($row->removed_at) {
            throw ValidationException::withMessages(['claim_party' => 'A removed claim party cannot be changed.']);
        }
        $this->assertOpen($row->claim);

        return DB::transaction(function () use ($row, $data, $actor) {
            $merged = array_merge($row->only(['contact_phone', 'contact_email', 'consent_given', 'consent_basis', 'is_self']),
                ['bank_account_masked' => $row->bank_account_masked], $data);
            $this->assertConsent($merged);
            if (isset($data['role'])) {
                $this->assertSingleClaimant($row->claim, $data['role'], $row->party_id, $row->id);
            }
            $old = $row->only($keys = array_values(array_diff(array_keys($this->attributes($data) + array_intersect_key($data, array_flip(['role', 'display_name']))), ['bank_account_encrypted'])));
            $row->fill(array_merge($this->attributes($data), array_intersect_key($data, array_flip(['role', 'display_name'])), ['updated_by' => $actor->id]))->save();
            $new = $row->only($keys);
            $changed = array_keys(array_filter($new, fn ($v, $k) => ($old[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH));
            if (array_key_exists('bank_account_number', $data)) {
                $changed[] = 'bank_account';
            }
            $this->audit->recordChange('claim.party.updated', 'claim', $row->claim_id, $this->redact($old), $this->redact($new),
                (string) ($data['reason'] ?? 'Claim party updated'), ['claim_party_id' => $row->id, 'changed' => array_values(array_unique($changed))]);
            $this->outbox->record('claim.party.updated', 'claim', $row->claim_id, ['claim_party_id' => $row->id, 'changed' => array_values(array_unique($changed))]);

            return $row->refresh();
        });
    }

    public function remove(ClaimInvolvedParty $row, string $reason, User $actor, ?string $effectiveTo = null): ClaimInvolvedParty
    {
        if ($row->removed_at) {
            throw ValidationException::withMessages(['claim_party' => 'This claim party is already removed.']);
        }
        $this->assertOpen($row->claim);
        $to = $effectiveTo ?? now()->toDateString();
        if ($row->effective_from && $to < $row->effective_from->toDateString()) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot precede the start date.']);
        }
        $row->update(['removed_at' => now(), 'removed_by' => $actor->id, 'removal_reason' => mb_substr($reason, 0, 500), 'effective_to' => $to]);
        $this->audit->record('claim.party.removed', 'claim', $row->claim_id, ['claim_party_id' => $row->id, 'role' => $row->role, 'effective_to' => $to], $reason);
        $this->outbox->record('claim.party.removed', 'claim', $row->claim_id, ['claim_party_id' => $row->id, 'role' => $row->role]);

        return $row->refresh();
    }

    /** @return array{0: ?string, 1: ?string, 2: string, 3: int} */
    private function link(Claim $claim, array $data, User $actor): array
    {
        if (! empty($data['party_id'])) {
            $p = $this->merges->resolve($data['party_id']) ?? throw ValidationException::withMessages(['party_id' => 'Unknown party.']);

            return [$p->id, null, 'LINKED', 0];
        }
        if (! empty($data['partner_id'])) {
            $partner = Partner::whereKey($data['partner_id'])->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $claim->tenant_id))->first();
            if (! $partner || ! $partner->party_id) {
                throw ValidationException::withMessages(['partner_id' => 'Unknown provider.']);
            }

            return [$this->merges->resolve($partner->party_id)?->id ?? $partner->party_id, $partner->id, 'LINKED', 0];
        }
        if (empty($data['link_party'])) {
            return [null, null, 'UNLINKED', 0];
        }
        if (empty($data['display_name'])) {
            throw ValidationException::withMessages(['display_name' => 'A name is needed to match or create a party.']);
        }
        $phone = $data['contact_phone'] ?? null;
        $email = isset($data['contact_email']) ? mb_strtolower((string) $data['contact_email']) : null;
        $hit = DB::table('party_contacts')->where(fn ($q) => $q->when($phone, fn ($q) => $q->orWhere(fn ($q) => $q->where(['type' => 'PHONE', 'normalized_value' => $phone])))
            ->when($email, fn ($q) => $q->orWhere(fn ($q) => $q->where(['type' => 'EMAIL', 'normalized_value' => $email]))))
            ->when(! $phone && ! $email, fn ($q) => $q->whereRaw('1 = 0'))->value('party_id');
        if ($hit && ($p = $this->merges->resolve($hit))) {
            return [$p->id, null, 'MATCHED', 0];
        }
        $party = $this->parties->create(array_filter(['type' => $data['party_type'] ?? 'INDIVIDUAL', 'display_name' => $data['display_name'],
            'phone_e164' => $phone, 'email' => $email, 'date_of_birth' => $data['date_of_birth'] ?? null, 'registration_number' => $data['registration_number'] ?? null]));
        $candidates = count($this->matcher->scan($party, $claim->tenant_id, $actor));

        return [$party->id, null, $candidates > 0 ? 'REVIEW_PENDING' : 'CREATED', $candidates];
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        $out = array_intersect_key($data, array_flip(['contact_phone', 'contact_email', 'consent_given', 'consent_basis', 'notes', 'bank_name', 'bank_account_holder', 'effective_from']));
        if (array_key_exists('consent_given', $data) || array_key_exists('consent_basis', $data)) {
            $out['consent_recorded_at'] = ($data['consent_given'] ?? false) || ! empty($data['consent_basis']) ? now() : null;
        }
        if (array_key_exists('bank_account_number', $data)) {
            $n = $data['bank_account_number'] ? mb_strtoupper((string) preg_replace('/\s+/', '', (string) $data['bank_account_number'])) : null;
            $out['bank_account_encrypted'] = $n;
            $out['bank_account_masked'] = $n ? str_repeat('•', max(0, mb_strlen($n) - 4)).mb_substr($n, -4) : null;
        }

        return $out;
    }

    private function assertConsent(array $d): void
    {
        $sensitive = filled($d['contact_phone'] ?? null) || filled($d['contact_email'] ?? null) || filled($d['bank_account_number'] ?? null) || filled($d['bank_account_masked'] ?? null);
        if ($sensitive && ! ($d['is_self'] ?? false) && ! ($d['consent_given'] ?? false) && empty($d['consent_basis'])) {
            throw ValidationException::withMessages(['consent_basis' => 'Contact or bank details of a party need consent or a recorded lawful basis.']);
        }
    }

    private function assertSingleClaimant(Claim $claim, string $role, ?string $partyId, ?string $exceptId = null): void
    {
        if ($role === 'CLAIMANT' && ClaimInvolvedParty::where(['claim_id' => $claim->id, 'role' => 'CLAIMANT'])->active()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            throw ValidationException::withMessages(['role' => 'This claim already has an active claimant.']);
        }
    }

    private function assertOpen(Claim $claim): void
    {
        if ($claim->status === 'CLOSED') {
            throw ValidationException::withMessages(['claim' => 'Parties cannot be changed on a closed claim.']);
        }
    }

    private function nameFor(?string $partyId): string
    {
        $name = $partyId ? Party::whereKey($partyId)->value('display_name') : null;

        return $name ?? throw ValidationException::withMessages(['display_name' => 'The display name is required.']);
    }

    /** @return array<string, mixed> */
    private function redact(array $v): array
    {
        foreach (['contact_phone', 'contact_email'] as $k) {
            if (isset($v[$k]) && $v[$k] !== null) {
                $v[$k] = '[redacted]';
            }
        }

        return $v;
    }
}
