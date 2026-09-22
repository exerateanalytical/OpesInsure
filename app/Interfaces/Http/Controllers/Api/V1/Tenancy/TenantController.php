<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Tenancy;

use App\Application\Audit\AuditWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TenantController
{
    public function index(Request $r) { return response()->json(['data' => DB::table('tenants')->join('tenant_memberships','tenants.id','=','tenant_memberships.tenant_id')->where('tenant_memberships.user_id',$r->user()->id)->whereNull('tenants.deleted_at')->select('tenants.id','tenants.type','tenants.legal_name','tenants.trade_name','tenants.slug','tenants.status','tenants.primary_locale')->paginate(25)]); }
    public function store(Request $r, AuditWriter $audit) {
        $d=$r->validate(['type'=>'required|in:BROKER,CARRIER,AGENCY,PLATFORM','legal_name'=>'required|string|max:160','trade_name'=>'nullable|string|max:160','slug'=>'required|alpha_dash|max:80|unique:tenants,slug','registration_number'=>'nullable|string|max:80','tax_number'=>'nullable|string|max:80','primary_locale'=>'required|in:en,fr']);
        $id=(string)Str::uuid();
        DB::transaction(function()use($d,$id,$r,$audit){DB::table('tenants')->insert([...$d,'id'=>$id,'status'=>'PENDING','country_code'=>'CM','currency'=>'XAF','settings'=>'{}','created_at'=>now(),'updated_at'=>now()]); DB::table('tenant_memberships')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$id,'user_id'=>$r->user()->id,'role_code'=>'TENANT_OWNER','status'=>'ACTIVE','created_at'=>now(),'updated_at'=>now()]); $audit->record('tenant.created','tenant',$id,['type'=>$d['type']]);});
        return response()->json(['data'=>['id'=>$id,'status'=>'PENDING']],201);
    }
    public function show(string $tenant) { abort_unless($tenant===app(\App\Domain\Tenancy\TenantContext::class)->id(),404); return response()->json(['data'=>DB::table('tenants')->where('id',$tenant)->whereNull('deleted_at')->firstOrFail()]); }
    public function update(Request $r,string $tenant,AuditWriter $audit) { abort_unless($tenant===app(\App\Domain\Tenancy\TenantContext::class)->id(),404); $d=$r->validate(['trade_name'=>'sometimes|nullable|string|max:160','primary_locale'=>'sometimes|in:en,fr','settings'=>'sometimes|array']); if(isset($d['settings']))$d['settings']=json_encode($d['settings']); DB::table('tenants')->where('id',$tenant)->update([...$d,'updated_at'=>now()]); $audit->record('tenant.updated','tenant',$tenant,['fields'=>array_keys($d)]); return response()->json(['data'=>['id'=>$tenant,'updated'=>true]]); }
}
