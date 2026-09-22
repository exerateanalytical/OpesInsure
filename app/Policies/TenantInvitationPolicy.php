<?php
namespace App\Policies;
use App\Models\TenantInvitation;use App\Models\User;
final class TenantInvitationPolicy
{
private function platform(User$u):bool{return$u->memberships()->where('status','ACTIVE')->whereIn('role_code',['SYSTEM_ADMIN','PLATFORM_ADMIN'])->exists();}
private function manages(User$u,string$tenantId):bool{return$this->platform($u)||$u->memberships()->where('tenant_id',$tenantId)->where('status','ACTIVE')->where('role_code','BROKER_ADMIN')->exists();}
public function viewAny(User$u):bool{return$u->memberships()->where('status','ACTIVE')->whereIn('role_code',['SYSTEM_ADMIN','PLATFORM_ADMIN','BROKER_ADMIN'])->exists();}
public function view(User$u,TenantInvitation$i):bool{return$this->manages($u,$i->tenant_id);}public function create(User$u):bool{return$this->viewAny($u);}public function update(User$u,TenantInvitation$i):bool{return$this->manages($u,$i->tenant_id);}public function delete(User$u,TenantInvitation$i):bool{return false;}
}
