<?php
declare(strict_types=1);
namespace App\Application\Identity;
use App\Application\Audit\AuditWriter;use App\Models\MfaMethod;use App\Models\User;use App\Models\UserDevice;use Illuminate\Support\Facades\DB;use Illuminate\Support\Facades\Hash;use Illuminate\Support\Str;use Illuminate\Validation\ValidationException;
final class AccountSecurityService
{
    public function __construct(private readonly TotpService$totp,private readonly AuditWriter$audit){}
    public function beginTotp(User$user):MfaMethod{$user->mfaMethods()->where('type','TOTP')->whereNull('disabled_at')->update(['disabled_at'=>now()]);$m=MfaMethod::create(['user_id'=>$user->id,'type'=>'TOTP','secret_encrypted'=>$this->totp->secret()]);$this->event($user,'MFA_ENROLLMENT_STARTED','INFO');return$m;}
    /** @return list<string> */ public function confirmTotp(MfaMethod$method,string$code):array{if($method->disabled_at||$method->verified_at||!$this->totp->verify((string)$method->secret_encrypted,$code))throw ValidationException::withMessages(['code'=>__('wave0.mfa_invalid')]);return DB::transaction(function()use($method){$method->update(['verified_at'=>now()]);$codes=[];for($i=0;$i<10;$i++){$plain=strtoupper(Str::random(10));$codes[]=$plain;DB::table('mfa_recovery_codes')->insert(['id'=>(string)Str::uuid(),'user_id'=>$method->user_id,'code_hash'=>Hash::make($plain),'created_at'=>now(),'updated_at'=>now()]);}$this->event($method->user,'MFA_ENABLED','NOTICE');$this->audit->record('identity.mfa.enabled','user',$method->user_id);return$codes;});}
    public function revokeDevice(UserDevice$device,User$actor):void{$device->update(['trusted_at'=>null,'revoked_at'=>now()]);$actor->tokens()->delete();$this->event($device->user,'DEVICE_REVOKED','NOTICE',['device_id'=>$device->id]);$this->audit->record('identity.device.revoked','user_device',$device->id);}
    private function event(User$user,string$type,string$severity,array$metadata=[]):void{DB::table('security_events')->insert(['id'=>(string)Str::uuid(),'user_id'=>$user->id,'type'=>$type,'severity'=>$severity,'ip_hash'=>request()->ip()?hash('sha256',request()->ip()):null,'user_agent_hash'=>request()->userAgent()?hash('sha256',request()->userAgent()):null,'metadata'=>json_encode($metadata),'occurred_at'=>now()]);}
}
