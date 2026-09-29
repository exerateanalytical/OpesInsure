<?php
namespace App\Policies;
use App\Application\WebExperiences\PortalScope;use App\Domain\Tenancy\TenantContext;use App\Models\TenantInvitation;use App\Models\User;
final class TenantInvitationPolicy
{
private function platform(User$u):bool{return$u->memberships()->where('status','ACTIVE')->whereIn('role_code',['SYSTEM_ADMIN','PLATFORM_ADMIN'])->exists();}
private function manages(User$u,string$tenantId):bool{return$this->platform($u)||$u->memberships()->where('tenant_id',$tenantId)->where('status','ACTIVE')->where('role_code','BROKER_ADMIN')->exists();}
/**
 * P4 (owner 2026-09-29): inside /insurer the staff invitations follow the API permission (identity.invite, POST
 * /invitations) in the portal tenant only — no role-name shortcut. InvitationService::issue still refuses role
 * escalation (PlatformAuthority::assertMayGrant).
 */
private function portal():bool{return PortalScope::panel()==='insurer';}
private function portalAllows(User$u,?string$tenantId=null):bool{$t=rescue(fn()=>app(TenantContext::class)->id(),null,false);return$t!==null&&($tenantId===null||$tenantId===$t)&&$u->hasPermission('identity.invite');}
public function viewAny(User$u):bool{if($this->portal())return$this->portalAllows($u);return$u->memberships()->where('status','ACTIVE')->whereIn('role_code',['SYSTEM_ADMIN','PLATFORM_ADMIN','BROKER_ADMIN'])->exists();}
public function view(User$u,TenantInvitation$i):bool{if($this->portal())return$this->portalAllows($u,$i->tenant_id);return$this->manages($u,$i->tenant_id);}public function create(User$u):bool{if($this->portal())return$this->portalAllows($u);/* UI audit 2026-09-27: same permission as POST /invitations */return$this->viewAny($u)&&$u->hasPermission('identity.invite');}public function update(User$u,TenantInvitation$i):bool{if($this->portal())return$this->portalAllows($u,$i->tenant_id);return$this->manages($u,$i->tenant_id);}public function delete(User$u,TenantInvitation$i):bool{return false;}
}
