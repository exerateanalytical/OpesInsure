<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Identity;
use App\Application\Identity\InvitationService;use App\Models\Tenant;use App\Models\TenantInvitation;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;
final class InvitationController
{
public function store(Request$r,InvitationService$s):JsonResponse{$d=$r->validate(['tenant_id'=>'required|uuid|exists:tenants,id','recipient_email'=>'nullable|required_without:recipient_phone_e164|email','recipient_phone_e164'=>'nullable|required_without:recipient_email|string|max:20','role_code'=>'required|in:PLATFORM_ADMIN,COMPLIANCE_ADMIN,BROKER_ADMIN,BROKER_STAFF,AGENT','ttl_hours'=>'sometimes|integer|min:1|max:168']);$result=$s->issue(Tenant::findOrFail($d['tenant_id']),$r->user(),$d['recipient_email']??null,$d['recipient_phone_e164']??null,$d['role_code'],$d['ttl_hours']??72);return response()->json(['data'=>['id'=>$result['invitation']->id,'status'=>'PENDING','expires_at'=>$result['invitation']->expires_at,'token'=>$result['token']]],201);}
public function accept(Request$r,InvitationService$s):JsonResponse{$d=$r->validate(['token'=>'required|string|size:64']);$m=$s->accept($d['token'],$r->user());return response()->json(['data'=>['membership_id'=>$m->id,'tenant_id'=>$m->tenant_id,'role_code'=>$m->role_code]]);}
public function destroy(TenantInvitation$invitation,InvitationService$s):JsonResponse{$s->revoke($invitation);return response()->json(['data'=>['id'=>$invitation->id,'status'=>'REVOKED']]);}
}
