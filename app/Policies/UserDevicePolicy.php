<?php
namespace App\Policies;
use App\Models\User;use App\Models\UserDevice;
final class UserDevicePolicy{private function platform(User$u):bool{return$u->memberships()->where('status','ACTIVE')->whereIn('role_code',['SYSTEM_ADMIN','PLATFORM_ADMIN'])->exists();}public function viewAny(User$u):bool{return$this->platform($u);}public function view(User$u,UserDevice$d):bool{return$u->id===$d->user_id||$this->platform($u);}public function update(User$u,UserDevice$d):bool{return$u->id===$d->user_id||$this->platform($u);}public function delete(User$u,UserDevice$d):bool{return false;}}
