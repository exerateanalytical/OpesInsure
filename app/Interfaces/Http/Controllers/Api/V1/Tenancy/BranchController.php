<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Tenancy;
use App\Application\Audit\AuditWriter;use App\Models\TenantBranch;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;
final class BranchController
{
public function index(Request$r):JsonResponse{return response()->json(['data'=>TenantBranch::query()->where('tenant_id',app(\App\Domain\Tenancy\TenantContext::class)->id())->with('manager:id,full_name')->paginate(25)]);}
public function store(Request$r,AuditWriter$a):JsonResponse{$d=$r->validate(['code'=>'required|alpha_dash|max:40','name'=>'required|string|max:160','phone_e164'=>'nullable|string|max:20','email'=>'nullable|email','timezone'=>'nullable|timezone','manager_user_id'=>'nullable|uuid|exists:users,id','address'=>'sometimes|array']);$b=TenantBranch::create([...$d,'tenant_id'=>app(\App\Domain\Tenancy\TenantContext::class)->id(),'status'=>'ACTIVE']);$a->record('tenant.branch.created','tenant_branch',$b->id);return response()->json(['data'=>$b],201);}
}
