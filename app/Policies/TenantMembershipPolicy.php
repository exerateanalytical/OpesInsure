<?php
namespace App\Policies;
use App\Models\TenantMembership;use App\Models\User;
final class TenantMembershipPolicy{private function manage(User$u):bool{return$u->memberships()->where('status','ACTIVE')->whereIn('role_code',['SYSTEM_ADMIN','PLATFORM_ADMIN','BROKER_ADMIN'])->exists();}public function viewAny(User$u):bool{return$this->manage($u);}public function view(User$u,TenantMembership$m):bool{return$this->manage($u);}public function create(User$u):bool{return$this->manage($u);}public function update(User$u,TenantMembership$m):bool{return$this->manage($u)&&$u->id!==$m->user_id;}public function delete(User$u,TenantMembership$m):bool{return false;}}
