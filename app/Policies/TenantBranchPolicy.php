<?php
namespace App\Policies;
use App\Models\TenantBranch;use App\Models\User;
final class TenantBranchPolicy{private function manage(User$u):bool{return$u->memberships()->where('status','ACTIVE')->whereIn('role_code',['SYSTEM_ADMIN','PLATFORM_ADMIN','BROKER_ADMIN'])->exists();}public function viewAny(User$u):bool{return$this->manage($u);}public function view(User$u,TenantBranch$b):bool{return$this->manage($u);}public function create(User$u):bool{return$this->manage($u);}public function update(User$u,TenantBranch$b):bool{return$this->manage($u);}public function delete(User$u,TenantBranch$b):bool{return false;}}
