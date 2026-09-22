<?php
declare(strict_types=1);
namespace App\Application\Identity;
use App\Application\Audit\AuditWriter;use App\Models\TenantMembership;use App\Models\User;use Illuminate\Support\Facades\DB;use Illuminate\Validation\ValidationException;
final class MembershipService{public function __construct(private readonly AuditWriter$audit){}public function revoke(TenantMembership$m,User$actor,string$reason):void{if($m->status==='REVOKED')throw ValidationException::withMessages(['status'=>__('wave0.membership_already_revoked')]);if($m->user_id===$actor->id)throw ValidationException::withMessages(['status'=>__('wave0.cannot_revoke_self')]);DB::transaction(function()use($m,$actor,$reason){$m->update(['status'=>'REVOKED','revoked_at'=>now(),'revoked_by'=>$actor->id,'revocation_reason'=>$reason]);$m->roles()->detach();$m->user->tokens()->delete();$this->audit->record('identity.membership.revoked','tenant_membership',$m->id,['role_code'=>$m->role_code],$reason);});}}
