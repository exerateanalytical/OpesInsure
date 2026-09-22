<?php
namespace App\Policies;use App\Models\Partner;use App\Models\User;
final class PartnerPolicy{private function allowed(User$u):bool{return$u->memberships()->where('status','ACTIVE')->whereIn('role_code',['SYSTEM_ADMIN','PLATFORM_ADMIN','COMPLIANCE_ADMIN'])->exists();}public function viewAny(User$u):bool{return$this->allowed($u);}public function view(User$u,Partner$p):bool{return$this->allowed($u);}public function create(User$u):bool{return$this->allowed($u);}public function update(User$u,Partner$p):bool{return false;}public function delete(User$u,Partner$p):bool{return false;}}
