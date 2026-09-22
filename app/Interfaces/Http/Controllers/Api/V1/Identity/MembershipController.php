<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Identity;
use App\Application\Identity\MembershipService;use App\Models\TenantMembership;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;
final class MembershipController{public function revoke(Request$r,TenantMembership$membership,MembershipService$s):JsonResponse{$d=$r->validate(['reason'=>'required|string|min:10|max:500']);$s->revoke($membership,$r->user(),$d['reason']);return response()->json(['data'=>['id'=>$membership->id,'status'=>'REVOKED']]);}}
