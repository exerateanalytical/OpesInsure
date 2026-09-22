<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Identity;
use App\Application\Identity\AccountSecurityService;use App\Models\MfaMethod;use App\Models\UserDevice;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;
final class SecurityController
{
public function beginTotp(Request$r,AccountSecurityService$s):JsonResponse{$m=$s->beginTotp($r->user());return response()->json(['data'=>['method_id'=>$m->id,'secret'=>$m->secret_encrypted,'otpauth_uri'=>'otpauth://totp/OpesInsure:'.rawurlencode($r->user()->email??$r->user()->phone_e164).'?secret='.$m->secret_encrypted.'&issuer=OpesInsure']]);}
public function confirmTotp(Request$r,MfaMethod$method,AccountSecurityService$s):JsonResponse{abort_unless($method->user_id===$r->user()->id,404);$d=$r->validate(['code'=>'required|digits:6']);$codes=$s->confirmTotp($method,$d['code']);return response()->json(['data'=>['enabled'=>true,'recovery_codes'=>$codes]]);}
public function devices(Request$r):JsonResponse{return response()->json(['data'=>$r->user()->devices()->latest('last_seen_at')->get(['id','name','platform','last_seen_at','trusted_at','revoked_at'])]);}
public function revokeDevice(Request$r,UserDevice$device,AccountSecurityService$s):JsonResponse{abort_unless($device->user_id===$r->user()->id||$r->user()->can('update',$device->user),404);$s->revokeDevice($device,$r->user());return response()->json(['data'=>['id'=>$device->id,'revoked'=>true]]);}
}
