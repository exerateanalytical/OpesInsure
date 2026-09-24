<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditWriter;
use App\Models\Partner;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvitationService
{
    public function __construct(private readonly AuditWriter $audit, private readonly PartyResolver $parties) {}

    /** @return array{invitation: TenantInvitation, token: string} */
    public function issue(Tenant $tenant, User $actor, ?string $email, ?string $phone, string $roleCode, int $ttlHours = 72, ?string $carrierId = null): array
    {
        if (($email === null) === ($phone === null)) {
            throw ValidationException::withMessages(['recipient' => __('wave0.invitation_one_recipient')]);
        }

        $duplicate = TenantInvitation::query()->where('tenant_id', $tenant->id)->where('status', 'PENDING')
            ->where(fn ($q) => $email ? $q->where('recipient_email', mb_strtolower($email)) : $q->where('recipient_phone_e164', $phone))->exists();
        if ($duplicate) throw ValidationException::withMessages(['recipient' => __('wave0.invitation_pending')]);

        $token = Str::random(64);
        $invitation = TenantInvitation::create([
            'tenant_id'=>$tenant->id, 'recipient_email'=>$email ? mb_strtolower($email) : null, 'recipient_phone_e164'=>$phone,
            'role_code'=>$roleCode, 'carrier_id'=>in_array($roleCode, RoleCatalogue::CARRIER_ROLES, true) ? $carrierId : null,
            'token_hash'=>hash('sha256', $token), 'status'=>'PENDING', 'expires_at'=>now()->addHours($ttlHours), 'invited_by'=>$actor->id,
        ]);
        $this->audit->record('identity.invitation.issued', 'tenant_invitation', $invitation->id, ['tenant_id'=>$tenant->id,'role_code'=>$roleCode]);
        return compact('invitation', 'token');
    }

    /**
     * Accepting an invitation yields a membership that actually works:
     *  - the tenant Role of the invited code is attached (created with
     *    RoleCatalogue defaults if the tenant has none yet), so permission:
     *    gates pass;
     *  - AGENT / BROKER_* invitees get a Party (reusing theirs if any) and a
     *    Partner of type AGENT / BROKER on that SAME party, which is how
     *    PartyResolver::partnerForUser() / AgentPartnerResolver find them.
     *    A newly created Partner is PENDING — an administrator activates it
     *    (licence verification or the admin "Change status" action);
     *  - CARRIER_* invitees are linked to the invitation's carrier.
     */
    public function accept(string $token, User $user): TenantMembership
    {
        return DB::transaction(function () use ($token, $user): TenantMembership {
            $invitation = TenantInvitation::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $invitation || $invitation->status !== 'PENDING' || $invitation->expires_at->isPast()) {
                throw ValidationException::withMessages(['token'=>__('wave0.invitation_invalid')]);
            }
            $matches = ($invitation->recipient_email && hash_equals($invitation->recipient_email, mb_strtolower((string)$user->email)))
                || ($invitation->recipient_phone_e164 && $user->phone_e164 && hash_equals($invitation->recipient_phone_e164, $user->phone_e164));
            if (! $matches) throw ValidationException::withMessages(['token'=>__('wave0.invitation_wrong_identity')]);

            $membership = TenantMembership::firstOrCreate(
                ['tenant_id'=>$invitation->tenant_id,'user_id'=>$user->id,'role_code'=>$invitation->role_code],
                ['status'=>'ACTIVE']
            );
            if ($invitation->carrier_id && $membership->carrier_id === null) {
                $membership->update(['carrier_id' => $invitation->carrier_id]);
            }

            $role = Role::firstOrCreate(
                ['tenant_id'=>$invitation->tenant_id, 'code'=>$invitation->role_code],
                ['permissions'=>RoleCatalogue::defaultPermissions($invitation->role_code), 'is_system'=>true]
            );
            $membership->roles()->syncWithoutDetaching([$role->id]);

            $partnerId = null;
            $partnerType = match (true) {
                $invitation->role_code === 'AGENT' => 'AGENT',
                str_starts_with($invitation->role_code, 'BROKER_') => 'BROKER',
                default => null,
            };
            if ($partnerType !== null) {
                $partnerId = $this->ensurePartner($user, $invitation->tenant_id, $partnerType)->id;
            }

            $invitation->update(['status'=>'ACCEPTED','accepted_at'=>now()]);
            $this->audit->record('identity.invitation.accepted', 'tenant_invitation', $invitation->id, ['membership_id'=>$membership->id, 'role_id'=>$role->id, 'partner_id'=>$partnerId]);
            return $membership->refresh();
        });
    }

    private function ensurePartner(User $user, string $tenantId, string $type): Partner
    {
        $party = $this->parties->forUser($user);

        if (! $party) {
            $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => $user->full_name ?: ($user->email ?? $user->phone_e164), 'status' => 'ACTIVE']);
            if ($user->phone_e164) {
                PartyContact::firstOrCreate(
                    ['party_id' => $party->id, 'type' => 'PHONE', 'normalized_value' => $user->phone_e164],
                    ['is_primary' => true]
                );
            }
        }
        if ($user->party_id !== $party->id) {
            $user->forceFill(['party_id' => $party->id])->save();
        }

        $partner = Partner::where('party_id', $party->id)->first();
        if ($partner) {
            return $partner;
        }

        $partner = Partner::create(['tenant_id' => $tenantId, 'party_id' => $party->id, 'type' => $type, 'status' => 'PENDING', 'compliance' => []]);
        $this->audit->record('partner.created', 'partner', $partner->id, ['type' => $type, 'source' => 'invitation']);

        return $partner;
    }

    public function revoke(TenantInvitation $invitation): void
    {
        if ($invitation->status !== 'PENDING') throw ValidationException::withMessages(['status'=>__('wave0.invitation_not_pending')]);
        $invitation->update(['status'=>'REVOKED']);
        $this->audit->record('identity.invitation.revoked', 'tenant_invitation', $invitation->id);
    }
}
