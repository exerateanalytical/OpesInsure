<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Tenancy;
use App\Application\Tenancy\TenantLifecycleService;use App\Models\Tenant;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;
final class TenantLifecycleController{public function __invoke(Request$r,Tenant$tenant,TenantLifecycleService$s):JsonResponse{$d=$r->validate(['status'=>'required|in:ACTIVE,SUSPENDED,CLOSED','reason'=>'required|string|max:64','notes'=>'required|string|min:10|max:1000']);$tenant=$s->transition($tenant,$d['status'],$d['reason'],$d['notes'],$r->user());return response()->json(['data'=>['id'=>$tenant->id,'status'=>$tenant->status]]);}}
