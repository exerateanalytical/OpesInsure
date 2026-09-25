<?php

declare(strict_types=1);

namespace App\Application\Customers\Roles;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\Party;
use App\Models\Parties\PartyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PTY-002 — golden-record roles. One party (parties) holds many explicit, bitemporal roles; a role is never
 * implied by another (LOCK-006: policyholder ≠ insured ≠ beneficiary ≠ payer). Rows are never updated in place:
 * a change supersedes the current row (system time) and records a new one (business time).
 */
final class PartyRoleService
{
    /**
     * Platform working set of contract person roles. The CIMA dictionary requires 16 distinct person roles but its
     * official list is not in the repository: codes here are the platform's own (cima_code UNVERIFIED = null).
     * The first four mirror master data list person_role.
     *
     * @var array<string, array{en: string, fr: string, single_per_context: bool, cima_code: null}>
     */
    public const ROLES = [
        'POLICYHOLDER' => ['en' => 'Policyholder', 'fr' => 'Souscripteur', 'single_per_context' => true, 'cima_code' => null],
        'LIFE_ASSURED' => ['en' => 'Life assured', 'fr' => 'Assuré (vie)', 'single_per_context' => false, 'cima_code' => null],
        'BENEFICIARY' => ['en' => 'Beneficiary', 'fr' => 'Bénéficiaire', 'single_per_context' => false, 'cima_code' => null],
        'PAYER' => ['en' => 'Premium payer', 'fr' => 'Payeur des primes', 'single_per_context' => false, 'cima_code' => null],
        'INSURED' => ['en' => 'Insured', 'fr' => 'Assuré', 'single_per_context' => false, 'cima_code' => null],
        'CLAIMANT' => ['en' => 'Claimant', 'fr' => 'Demandeur', 'single_per_context' => false, 'cima_code' => null],
        'THIRD_PARTY' => ['en' => 'Third party', 'fr' => 'Tiers', 'single_per_context' => false, 'cima_code' => null],
        'DRIVER' => ['en' => 'Driver', 'fr' => 'Conducteur', 'single_per_context' => false, 'cima_code' => null],
        'VEHICLE_OWNER' => ['en' => 'Vehicle owner', 'fr' => 'Propriétaire du véhicule', 'single_per_context' => false, 'cima_code' => null],
        'LOSS_PAYEE' => ['en' => 'Loss payee', 'fr' => 'Bénéficiaire de l\'indemnité', 'single_per_context' => false, 'cima_code' => null],
        'GUARANTOR' => ['en' => 'Guarantor', 'fr' => 'Garant', 'single_per_context' => false, 'cima_code' => null],
        'LEGAL_REPRESENTATIVE' => ['en' => 'Legal representative', 'fr' => 'Représentant légal', 'single_per_context' => false, 'cima_code' => null],
        'EMPLOYER' => ['en' => 'Employer (group contract)', 'fr' => 'Employeur (contrat groupe)', 'single_per_context' => false, 'cima_code' => null],
        'MEMBER' => ['en' => 'Group member', 'fr' => 'Adhérent', 'single_per_context' => false, 'cima_code' => null],
        'WITNESS' => ['en' => 'Witness', 'fr' => 'Témoin', 'single_per_context' => false, 'cima_code' => null],
        'CUSTOMER' => ['en' => 'Customer (prospect / account)', 'fr' => 'Client (prospect / compte)', 'single_per_context' => false, 'cima_code' => null],
        // Owner Workflow Data Master v1 (party_roles, PLATFORM_NORMALIZED): owner codes with no equivalent above.
        // OWNER is the generic owner of an insured object (VEHICLE_OWNER stays its motor specialisation); PAYEE receives a
        // payment (LOSS_PAYEE stays the claims specialisation); EMPLOYEE is distinct from MEMBER (members need not be employees).
        'PAYEE' => ['en' => 'Payee', 'fr' => 'Bénéficiaire du paiement', 'single_per_context' => false, 'cima_code' => null],
        'OWNER' => ['en' => 'Owner (insured object)', 'fr' => 'Propriétaire (objet assuré)', 'single_per_context' => false, 'cima_code' => null],
        'EMPLOYEE' => ['en' => 'Employee', 'fr' => 'Salarié', 'single_per_context' => false, 'cima_code' => null],
        'DEPENDANT' => ['en' => 'Dependant', 'fr' => 'Ayant droit', 'single_per_context' => false, 'cima_code' => null],
        'BENEFICIAL_OWNER' => ['en' => 'Beneficial owner', 'fr' => 'Bénéficiaire effectif', 'single_per_context' => false, 'cima_code' => null],
        'DIRECTOR' => ['en' => 'Director', 'fr' => 'Dirigeant', 'single_per_context' => false, 'cima_code' => null],
        'TRUSTEE' => ['en' => 'Trustee', 'fr' => 'Fiduciaire (trustee)', 'single_per_context' => false, 'cima_code' => null],
        'SETTLOR' => ['en' => 'Settlor', 'fr' => 'Constituant', 'single_per_context' => false, 'cima_code' => null],
    ];

    /** Owner Workflow Data Master v1: the 16 owner role codes (all present in ROLES). */
    public const OWNER_WORKFLOW_ROLES = ['POLICYHOLDER', 'INSURED', 'BENEFICIARY', 'CLAIMANT', 'PAYER', 'PAYEE', 'OWNER', 'DRIVER', 'EMPLOYER',
        'EMPLOYEE', 'DEPENDANT', 'BENEFICIAL_OWNER', 'DIRECTOR', 'TRUSTEE', 'SETTLOR', 'WITNESS'];

    /** Accepted spellings resolved to the canonical code (never stored). */
    public const ALIASES = [
        'POLICY_HOLDER' => 'POLICYHOLDER', 'SUBSCRIBER' => 'POLICYHOLDER', 'SOUSCRIPTEUR' => 'POLICYHOLDER',
        'PREMIUM_PAYER' => 'PAYER', 'DEPENDENT' => 'DEPENDANT', 'UBO' => 'BENEFICIAL_OWNER', 'ULTIMATE_BENEFICIAL_OWNER' => 'BENEFICIAL_OWNER',
        'ASSURE' => 'INSURED', 'CONDUCTEUR' => 'DRIVER', 'TEMOIN' => 'WITNESS', 'AYANT_DROIT' => 'DEPENDANT',
    ];

    public const SOURCE = 'OWNER_WORKFLOW_DATA_MASTER_V1';

    public static function canonical(string $code): string
    {
        $c = strtoupper(trim($code));

        return self::ALIASES[$c] ?? $c;
    }

    public const CONTEXT_TYPES = ['policy', 'proposal', 'quote', 'claim'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    /** @param array{role_code: string, context_type?: ?string, context_id?: ?string, valid_from?: ?string, valid_to?: ?string, details?: array, source?: string} $d */
    public function assign(Party $party, array $d, ?string $tenantId, ?string $actorId): PartyRole
    {
        $code = self::canonical((string) $d['role_code']);
        if (! isset(self::ROLES[$code])) {
            throw ValidationException::withMessages(['role_code' => 'Unknown party role.']);
        }
        if ($party->merged_into_id) {
            throw ValidationException::withMessages(['party' => 'This party was merged; assign roles to the surviving party.']);
        }
        $ctxType = $d['context_type'] ?? null;
        $ctxId = $d['context_id'] ?? null;
        if (($ctxType === null) !== ($ctxId === null) || ($ctxType !== null && ! in_array($ctxType, self::CONTEXT_TYPES, true))) {
            throw ValidationException::withMessages(['context_type' => 'context_type and context_id go together (policy, proposal, quote or claim).']);
        }
        $from = CarbonImmutable::parse($d['valid_from'] ?? now());
        $to = isset($d['valid_to']) ? CarbonImmutable::parse($d['valid_to']) : null;
        if ($to && $to->lte($from)) {
            throw ValidationException::withMessages(['valid_to' => 'valid_to must be after valid_from.']);
        }

        return DB::transaction(function () use ($party, $code, $ctxType, $ctxId, $from, $to, $d, $tenantId, $actorId) {
            $current = $this->current()->where('party_id', $party->id)->where('role_code', $code)
                ->where('context_type', $ctxType)->where('context_id', $ctxId)->where('tenant_id', $tenantId)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $from))->lockForUpdate()->exists();
            if ($current) {
                throw ValidationException::withMessages(['role_code' => 'The party already holds this role here.']);
            }
            if ($ctxType && self::ROLES[$code]['single_per_context']
                && $this->current()->where('role_code', $code)->where('context_type', $ctxType)->where('context_id', $ctxId)
                    ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $from))->exists()) {
                throw ValidationException::withMessages(['role_code' => "{$code} is already held by another party here; end it first."]);
            }
            $role = PartyRole::create([
                'party_id' => $party->id, 'tenant_id' => $tenantId, 'role_code' => $code, 'context_type' => $ctxType, 'context_id' => $ctxId,
                'valid_from' => $from, 'valid_to' => $to, 'recorded_at' => now(), 'source' => $d['source'] ?? 'MANUAL',
                'details' => $d['details'] ?? [], 'created_by' => $actorId,
            ]);
            $this->audit->record('party.role_assigned', 'party', $party->id, ['role_id' => $role->id, 'role_code' => $code, 'context_type' => $ctxType, 'context_id' => $ctxId]);
            $this->outbox->record('party.role_assigned', 'party', $party->id, ['party_id' => $party->id, 'role_id' => $role->id, 'role_code' => $code]);

            return $role;
        });
    }

    /** Ends a role in business time without losing history: the current row is superseded by a copy with valid_to. */
    public function end(PartyRole $role, string $validTo, string $reason, ?string $actorId): PartyRole
    {
        return DB::transaction(function () use ($role, $validTo, $reason, $actorId) {
            $row = PartyRole::whereKey($role->id)->lockForUpdate()->firstOrFail();
            if ($row->superseded_at) {
                throw ValidationException::withMessages(['role' => 'This role version was already superseded.']);
            }
            $to = CarbonImmutable::parse($validTo);
            if ($to->lte($row->valid_from) || ($row->valid_to && $row->valid_to->lte($to))) {
                throw ValidationException::withMessages(['valid_to' => 'valid_to must fall inside the current validity.']);
            }
            $now = now();
            $row->update(['superseded_at' => $now]);
            $next = PartyRole::create([...collect($row->getAttributes())->except(['id', 'created_at', 'updated_at', 'superseded_at', 'details'])->all(),
                'details' => $row->details ?? [], 'valid_to' => $to, 'recorded_at' => $now, 'supersedes_id' => $row->id, 'created_by' => $actorId]);
            $this->audit->record('party.role_ended', 'party', $row->party_id, ['role_id' => $next->id, 'supersedes_id' => $row->id, 'role_code' => $row->role_code, 'valid_to' => $to->toIso8601String()], $reason);

            return $next;
        });
    }

    /** Roles as valid at $validAt and as known at $knownAt (bitemporal read). */
    public function asOf(string $partyId, ?string $validAt = null, ?string $knownAt = null, ?string $tenantId = null): Collection
    {
        $valid = CarbonImmutable::parse($validAt ?? now());
        $known = CarbonImmutable::parse($knownAt ?? now());

        return PartyRole::where('party_id', $partyId)
            ->when($tenantId, fn ($q) => $q->where(fn ($x) => $x->where('tenant_id', $tenantId)->orWhereNull('tenant_id')))
            ->where('recorded_at', '<=', $known)->where(fn ($q) => $q->whereNull('superseded_at')->orWhere('superseded_at', '>', $known))
            ->where('valid_from', '<=', $valid)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $valid))
            ->orderBy('role_code')->get();
    }

    /** Everyone holding a role in a context (e.g. a policy): the policyholder, insureds and beneficiaries stay distinct rows. */
    public function forContext(string $type, string $id, ?string $tenantId = null): Collection
    {
        return $this->current()->where('context_type', $type)->where('context_id', $id)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('valid_from', '<=', now())->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()))
            ->orderBy('role_code')->get();
    }

    /**
     * cima_code stays null (UNVERIFIED) until the official CIMA role list is supplied; status is PLATFORM_NORMALIZED.
     *
     * @return list<array{code: string, label_en: string, label_fr: string, single_per_context: bool, cima_code: null, status: string, in_owner_workflow_master: bool, aliases: list<string>}>
     */
    public function catalogue(): array
    {
        return array_map(fn ($c, $r) => ['code' => $c, 'label_en' => $r['en'], 'label_fr' => $r['fr'], 'single_per_context' => $r['single_per_context'], 'cima_code' => $r['cima_code'],
            'status' => 'PLATFORM_NORMALIZED', 'in_owner_workflow_master' => in_array($c, self::OWNER_WORKFLOW_ROLES, true),
            'aliases' => array_keys(array_filter(self::ALIASES, fn ($to) => $to === $c))],
            array_keys(self::ROLES), self::ROLES);
    }

    private function current()
    {
        return PartyRole::query()->whereNull('superseded_at');
    }
}
