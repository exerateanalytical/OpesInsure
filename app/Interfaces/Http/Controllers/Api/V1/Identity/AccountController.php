<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Identity;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class AccountController
{
    public function register(Request $r) {
        $d=$r->validate(['full_name'=>'required|string|max:120','phone_e164'=>['required','regex:/^\+[1-9]\d{7,14}$/','unique:users,phone_e164'],'email'=>'nullable|email:rfc,dns|max:190|unique:users,email','password'=>'required|string|min:12|max:128|confirmed','locale'=>'required|in:en,fr','terms_version'=>'required|string|max:32']);
        $user=DB::transaction(function()use($d){$u=User::create(['id'=>(string)Str::uuid(),'full_name'=>$d['full_name'],'phone_e164'=>$d['phone_e164'],'email'=>$d['email']??null,'password'=>$d['password'],'locale'=>$d['locale'],'status'=>'PENDING_VERIFICATION']); return $u;});
        return response()->json(['data'=>['id'=>$user->id,'status'=>$user->status,'verification_required'=>true]],201);
    }
    public function me(Request $r) { return response()->json(['data'=>$r->user()->only(['id','full_name','email','phone_e164','locale','status','email_verified_at','phone_verified_at'])]); }
    public function changePassword(Request $r) { $d=$r->validate(['current_password'=>'required','password'=>'required|string|min:12|max:128|confirmed|different:current_password']); abort_unless(Hash::check($d['current_password'],$r->user()->password),422,'Current password is incorrect.'); $r->user()->forceFill(['password'=>$d['password']])->save(); $r->user()->tokens()->delete(); return response()->json(['data'=>['password_changed'=>true,'sessions_revoked'=>true]]); }
}
