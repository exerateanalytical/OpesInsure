<?php

declare(strict_types=1);

namespace App\Application\Partners\Onboarding;

use App\Application\Audit\AuditWriter;
use App\Application\Identity\InvitationService;
use App\Application\Partners\BulkOnboarding\BrokerOnboardingTarget;
use App\Models\Carrier;
use App\Models\OrganisationClaim;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Claim this organisation" for the insurers and brokers of the official register that have no account yet.
 *
 * Proof of authority is twofold: (1) a one-time code sent to the OFFICIAL email/phone already on record for the
 * institution (institution profile / register party contacts — never a destination the claimant types) and (2) an
 * authority document (board letter or mandate) + ID, held in the malware-scan queue until CLEAN. Without an official
 * contact the claim goes to manual verification only. A platform admin reviews (maker-checker, IntakeReview); on
 * approval the institution is linked to a new or existing tenant (insurer → CARRIER tenant with the carrier linked;
 * broker → BROKER tenant with the register partner linked) and the claimant is invited as CARRIER_SUPER_ADMIN /
 * BROKER_ADMIN. One live claim per institution (also a partial unique index); a later claimant can only raise a
 * dispute, which goes to the admins.
 */
final class OrganisationClaimService
{
    public const KINDS = ['insurer' => 'INSURER', 'broker' => 'BROKER'];

    public const DOCUMENT_KINDS = ['authority', 'id'];

    public const LOCKING = ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED'];

    public function __construct(
        private readonly PublicIntake $intake,
        private readonly IntakeReview $review,
        private readonly AuditWriter $audit,
        private readonly InvitationService $invitations,
        private readonly OrganisationProvisioner $provisioner,
    ) {}

    /**
     * The official-register institution behind a directory entry, or null (404).
     *
     * @return array{type: string, key: string, name: string, carrier: ?Carrier, partner: ?Partner}|null
     */
    public function institution(string $kind, string $id): ?array
    {
        if (! isset(self::KINDS[$kind]) || ! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            return null;
        }
        if ($kind === 'insurer') {
            $c = Carrier::query()->whereKey($id)->where('is_official_register', true)->where(fn ($q) => $q->whereNull('is_demo')->orWhere('is_demo', false))->first();

            return $c ? ['type' => 'INSURER', 'key' => 'CARRIER:'.$c->id, 'name' => (string) ($c->trade_name ?: $c->legal_name), 'carrier' => $c, 'partner' => null] : null;
        }
        $p = Partner::query()->whereKey($id)->where('type', 'BROKER')->where('is_official_register', true)->where(fn ($q) => $q->whereNull('is_demo')->orWhere('is_demo', false))->first();

        return $p ? ['type' => 'BROKER', 'key' => 'PARTNER:'.$p->id, 'name' => (string) ($p->trade_name ?: $p->legal_name), 'carrier' => null, 'partner' => $p] : null;
    }

    /**
     * The official destination for the one-time code: email first (always deliverable), then a mobile number when an
     * SMS provider is configured. Null means manual verification only.
     */
    public function officialContact(array $inst): ?string
    {
        $emails = collect();
        $phones = collect();
        if ($inst['carrier']) {
            $profile = DB::table('institution_profiles')->where('carrier_id', $inst['carrier']->id)->first(['emails', 'phones']);
            $emails = $emails->merge(json_decode((string) ($profile->emails ?? '[]'), true) ?: []);
            $phones = $phones->merge(json_decode((string) ($profile->phones ?? '[]'), true) ?: []);
        }
        $partyId = $inst['carrier']?->party_id ?? $inst['partner']?->party_id;
        if ($partyId) {
            $contacts = DB::table('party_contacts')->where('party_id', $partyId)->orderByDesc('is_primary')->get(['type', 'normalized_value']);
            $emails = $emails->merge($contacts->where('type', 'EMAIL')->pluck('normalized_value'));
            $phones = $phones->merge($contacts->where('type', 'PHONE')->pluck('normalized_value'));
        }
        $email = $emails->map(fn ($e) => mb_strtolower(trim((string) $e)))->first(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false);
        if ($email) {
            return $email;
        }
        $mobile = $phones->map(fn ($p) => BrokerOnboardingTarget::phone((string) $p))->first(fn ($p) => $p !== null && str_starts_with($p, '+2376'));

        return $mobile && $this->intake->smsAvailable() ? $mobile : null;
    }

    /** CLAIMED (already linked / approved), PENDING (a live claim or a code in flight) or null (claimable). */
    public function lockState(array $inst): ?string
    {
        $linked = $inst['partner'] ? $inst['partner']->tenant_id !== null
            : Partner::where('type', 'CARRIER')->whereNotNull('tenant_id')->where('compliance->carrier_id', $inst['carrier']->id)->exists()
                || Tenant::where('settings->carrier_id', $inst['carrier']->id)->exists();
        $claims = OrganisationClaim::where('institution_key', $inst['key'])->where('is_dispute', false);
        if ($linked || (clone $claims)->where('status', 'APPROVED')->exists()) {
            return 'CLAIMED';
        }
        $pending = (clone $claims)->where(fn ($q) => $q->whereIn('status', self::LOCKING)
            ->orWhere(fn ($w) => $w->where('status', 'UNVERIFIED')->where('code_expires_at', '>', now())))->exists();

        return $pending ? 'PENDING' : null;
    }

    /**
     * Files a claim (or, when the institution is locked and $dispute is set, a dispute for the admins).
     *
     * @param  array<string, UploadedFile|null>  $files
     * @return array{claim: OrganisationClaim, token: string}
     */
    public function submit(array $inst, array $data, array $files, ?string $ip, bool $dispute = false): array
    {
        $lock = $this->lockState($inst);
        if ($lock !== null && ! $dispute) {
            throw ValidationException::withMessages(['claimant_name' => __('org_claim.errors.locked')]);
        }
        if ($lock === null && $dispute) {
            $dispute = false;
        }
        $phone = ($data['claimant_phone'] ?? null) ? BrokerOnboardingTarget::phone((string) $data['claimant_phone']) : null;
        if (($data['claimant_phone'] ?? null) && $phone === null) {
            throw ValidationException::withMessages(['claimant_phone' => __('partner_apply.errors.phone')]);
        }
        $official = $dispute ? null : $this->officialContact($inst);
        [$token, $hash] = PublicIntake::newToken();

        $claim = DB::transaction(function () use ($inst, $data, $files, $ip, $hash, $dispute, $official, $phone) {
            $c = OrganisationClaim::create([
                'reference' => PublicIntake::reference('OC'), 'institution_type' => $inst['type'], 'carrier_id' => $inst['carrier']?->id,
                'partner_id' => $inst['partner']?->id, 'institution_key' => $inst['key'], 'institution_name' => $inst['name'],
                'status' => $dispute ? 'DISPUTED' : ($official ? 'UNVERIFIED' : 'SUBMITTED'), 'is_dispute' => $dispute,
                'claimant_name' => trim((string) $data['claimant_name']), 'claimant_position' => trim((string) $data['claimant_position']),
                'claimant_email' => mb_strtolower(trim((string) $data['claimant_email'])), 'claimant_phone' => $phone,
                'locale' => in_array($data['locale'] ?? null, ['en', 'fr'], true) ? $data['locale'] : 'fr',
                'verification_mode' => $official ? 'OFFICIAL_CONTACT' : 'MANUAL', 'official_destination_masked' => $official ? PublicIntake::mask($official) : null,
                'status_token_hash' => $hash, 'ip_hash' => PublicIntake::ipHash($ip), 'applicant_response' => $data['statement'] ?? null,
            ]);
            $c->forceFill(['documents' => $this->intake->storeDocuments($files, 'organisation-claims/'.$c->id, 'ORGANISATION_CLAIM')])->save();
            $this->audit->record('organisation_claim.'.($dispute ? 'disputed' : 'created'), 'organisation_claim', $c->id,
                ['institution' => $inst['key'], 'verification_mode' => $c->verification_mode]);

            return $c;
        });

        if ($official) {
            $this->sendCode($claim, $official);
        } else {
            $this->review->notify($claim, $dispute ? 'disputed' : 'received', []);
        }

        return ['claim' => $claim, 'token' => $token];
    }

    public function findByToken(string $token): ?OrganisationClaim
    {
        return OrganisationClaim::where('status_token_hash', PublicIntake::hash($token))->first();
    }

    /** Resend: always to the official destination on record (recomputed; never stored in clear). */
    public function resend(OrganisationClaim $claim): void
    {
        abort_unless($claim->status === 'UNVERIFIED', 409);
        $inst = $this->institutionOf($claim);
        $official = $inst ? $this->officialContact($inst) : null;
        abort_if($official === null, 409);
        $this->sendCode($claim, $official);
    }

    private function sendCode(OrganisationClaim $claim, string $official): void
    {
        $this->intake->sendCode($claim, $official,
            __('org_claim.code_message', ['name' => $claim->claimant_name, 'org' => $claim->institution_name], $claim->locale),
            __('org_claim.mail.code.subject', ['org' => $claim->institution_name], $claim->locale));
    }

    public function verify(OrganisationClaim $claim, string $code): bool
    {
        if ($claim->status !== 'UNVERIFIED') {
            return $claim->verified_at !== null;
        }
        if (! $this->intake->checkCode($claim, $code)) {
            $this->audit->record('organisation_claim.code_failed', 'organisation_claim', $claim->id, ['attempts' => $claim->code_attempts]);

            return false;
        }
        $inst = $this->institutionOf($claim);
        if ($inst === null || $this->lockState($inst) !== null) {
            $this->review->transition($claim, 'REJECTED', ['decision_note' => 'Institution claimed meanwhile.'], 'superseded');
            throw ValidationException::withMessages(['code' => __('org_claim.errors.locked')]);
        }
        try {
            $this->review->transition($claim, 'SUBMITTED', [], 'submitted', ['channel' => $claim->verification_channel]);
        } catch (QueryException) { // partial unique index: another claim got there first
            $claim->refresh();
            throw ValidationException::withMessages(['code' => __('org_claim.errors.locked')]);
        }
        $this->review->notify($claim, 'received', []);

        return true;
    }

    /** @param array<string, UploadedFile|null> $files */
    public function respond(OrganisationClaim $claim, string $response, array $files): void
    {
        if ($claim->status !== 'INFO_REQUESTED') {
            throw ValidationException::withMessages(['response' => __('partner_apply.errors.wrong_status', ['status' => $claim->status])]);
        }
        $this->review->respond($claim, $response, $this->intake->storeDocuments($files, 'organisation-claims/'.$claim->id, 'ORGANISATION_CLAIM'));
    }

    /** Checker decision: links the institution to a (new or existing) tenant and invites the claimant. */
    public function approve(OrganisationClaim $claim, User $checker, ?string $existingTenantId = null, ?string $note = null): OrganisationClaim
    {
        if ($claim->is_dispute) {
            throw ValidationException::withMessages(['status' => __('org_claim.errors.dispute_not_approvable')]);
        }
        $this->review->assertChecker($claim, $checker);
        if ($claim->recommendation !== 'APPROVE') {
            throw ValidationException::withMessages(['recommendation' => __('partner_apply.errors.recommended_reject')]);
        }
        if (! $this->intake->documentsClean($claim->documents ?? [])) {
            throw ValidationException::withMessages(['documents' => __('partner_apply.errors.documents_not_clean')]);
        }
        $inst = $this->institutionOf($claim);
        abort_if($inst === null, 404);
        // The claim itself holds the lock; anything else (a link made meanwhile) blocks.
        $linkedElsewhere = $inst['partner'] ? $inst['partner']->tenant_id !== null
            : Partner::where('type', 'CARRIER')->whereNotNull('tenant_id')->where('compliance->carrier_id', $inst['carrier']->id)->exists();
        if ($linkedElsewhere || OrganisationClaim::where('institution_key', $claim->institution_key)->where('status', 'APPROVED')->exists()) {
            throw ValidationException::withMessages(['status' => __('org_claim.errors.locked')]);
        }

        DB::transaction(function () use ($claim, $checker, $existingTenantId, $note, $inst) {
            $result = $inst['type'] === 'INSURER' ? $this->linkInsurer($claim, $inst['carrier'], $checker, $existingTenantId) : $this->linkBroker($claim, $inst['partner'], $checker, $existingTenantId);
            $claim->forceFill(['linked_tenant_id' => $result['tenant_id']])->save();
            $this->review->markApproved($claim, $checker, $result, $note);
        });

        return $claim->refresh();
    }

    private function linkInsurer(OrganisationClaim $claim, Carrier $carrier, User $actor, ?string $existingTenantId): array
    {
        if ($existingTenantId) {
            $tenant = Tenant::whereKey($existingTenantId)->whereIn('type', ['CARRIER', 'INSURER'])->first();
            $other = $tenant?->settings['carrier_id'] ?? null;
            if (! $tenant || ($other !== null && $other !== $carrier->id)) {
                throw ValidationException::withMessages(['tenant_id' => __('org_claim.errors.tenant_mismatch')]);
            }
        } else {
            $tenant = $this->provisioner->tenant('CARRIER', ['legal_name' => (string) ($carrier->legal_name ?: $carrier->trade_name), 'trade_name' => $carrier->trade_name, 'locale' => $claim->locale],
                ['source' => 'organisation_claim', 'claim_id' => $claim->id], 'Organisation claim '.$claim->reference, $actor);
            $this->provisioner->headOffice($tenant, ['locale' => $claim->locale, 'city' => DB::table('institution_offices')->where('carrier_id', $carrier->id)->where('office_type', 'HEAD_OFFICE')->value('city')], 'organisation_claim');
        }
        $tenant->update(['settings' => ['carrier_id' => $carrier->id] + ($tenant->settings ?? [])]);
        $partner = Partner::where('party_id', $carrier->party_id)->where('type', 'CARRIER')->first();
        if ($partner) {
            $partner->update(['tenant_id' => $tenant->id, 'compliance' => ['carrier_id' => $carrier->id] + ($partner->compliance ?? [])]);
            $this->audit->record('partner.linked', 'partner', $partner->id, ['tenant_id' => $tenant->id, 'source' => 'organisation_claim']);
        } else {
            $partner = Partner::create(['tenant_id' => $tenant->id, 'party_id' => $carrier->party_id, 'type' => 'CARRIER', 'status' => 'ACTIVE',
                'compliance' => ['source' => 'organisation_claim', 'carrier_id' => $carrier->id], 'legal_name' => $carrier->legal_name, 'trade_name' => $carrier->trade_name, 'country_code' => 'CM']);
            $this->audit->record('partner.created', 'partner', $partner->id, ['type' => 'CARRIER', 'source' => 'organisation_claim']);
        }
        $this->audit->record('carrier.claimed', 'carrier', $carrier->id, ['tenant_id' => $tenant->id, 'claim_id' => $claim->id]);
        $issued = $this->invitations->issue($tenant, $actor, $claim->claimant_email, null, 'CARRIER_SUPER_ADMIN', BrokerOnboardingTarget::INVITATION_TTL_HOURS, $carrier->id);
        $this->rehomeDocuments($claim, $tenant->id, $carrier->party_id);

        return ['tenant_id' => $tenant->id, 'carrier_id' => $carrier->id, 'partner_id' => $partner->id, 'invitation_id' => $issued['invitation']->id, 'existing_tenant' => (bool) $existingTenantId];
    }

    private function linkBroker(OrganisationClaim $claim, Partner $partner, User $actor, ?string $existingTenantId): array
    {
        if ($existingTenantId) {
            $tenant = Tenant::whereKey($existingTenantId)->where('type', 'BROKER')->first();
            if (! $tenant) {
                throw ValidationException::withMessages(['tenant_id' => __('org_claim.errors.tenant_mismatch')]);
            }
        } else {
            $tenant = $this->provisioner->tenant('BROKER', ['legal_name' => (string) ($partner->legal_name ?: $partner->trade_name), 'trade_name' => $partner->trade_name, 'locale' => $claim->locale],
                ['source' => 'organisation_claim', 'claim_id' => $claim->id], 'Organisation claim '.$claim->reference, $actor);
            $this->provisioner->headOffice($tenant, ['locale' => $claim->locale], 'organisation_claim');
        }
        $partner->update(['tenant_id' => $tenant->id]);
        $this->audit->record('partner.linked', 'partner', $partner->id, ['tenant_id' => $tenant->id, 'source' => 'organisation_claim', 'official_register' => true]);
        $issued = $this->invitations->issue($tenant, $actor, $claim->claimant_email, null, 'BROKER_ADMIN', BrokerOnboardingTarget::INVITATION_TTL_HOURS);
        $this->rehomeDocuments($claim, $tenant->id, $partner->party_id);

        return ['tenant_id' => $tenant->id, 'partner_id' => $partner->id, 'invitation_id' => $issued['invitation']->id, 'existing_tenant' => (bool) $existingTenantId];
    }

    private function rehomeDocuments(OrganisationClaim $claim, string $tenantId, ?string $partyId): void
    {
        $ids = array_column($claim->documents ?? [], 'document_id');
        if ($ids) {
            DB::table('documents')->whereIn('id', $ids)->update(['tenant_id' => $tenantId, 'party_id' => $partyId, 'updated_at' => now()]);
        }
    }

    private function institutionOf(OrganisationClaim $claim): ?array
    {
        return $claim->carrier_id ? $this->institution('insurer', $claim->carrier_id) : ($claim->partner_id ? $this->institution('broker', $claim->partner_id) : null);
    }
}
