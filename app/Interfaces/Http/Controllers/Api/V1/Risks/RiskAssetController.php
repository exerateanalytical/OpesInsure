<?php
declare(strict_types=1);
namespace App\Interfaces\Http\Controllers\Api\V1\Risks;
use App\Application\Risks\RiskAssetService;
use App\Domain\Tenancy\TenantContext;
use App\Models\{Party,RiskAsset,Tenant};
use Illuminate\Http\{JsonResponse,Request};
final class RiskAssetController
{
 private function scoped(string $id):RiskAsset{return RiskAsset::where('tenant_id',app(TenantContext::class)->id())->findOrFail($id);}
 public function index(Request $r):JsonResponse{$q=RiskAsset::where('tenant_id',app(TenantContext::class)->id());if($r->filled('party_id'))$q->where('party_id',$r->string('party_id'));if($r->filled('type'))$q->where('type',$r->string('type'));return response()->json(['data'=>$q->latest()->paginate(25)]);}
 public function store(Request $r,RiskAssetService $s,TenantContext $c):JsonResponse{$d=$r->validate(['party_id'=>'required|uuid|exists:parties,id','type'=>'required|in:VEHICLE,PROPERTY,TRAVELLER,HEALTH_MEMBER','external_reference'=>'nullable|string|max:100','display_name'=>'required|string|max:160','facts'=>'required|array']);return response()->json(['data'=>$s->create(Tenant::findOrFail($c->id()),Party::findOrFail($d['party_id']),$d,$r->user())],201);}
 public function show(string $risk_asset):JsonResponse{return response()->json(['data'=>$this->scoped($risk_asset)]);}
 public function update(Request $r,string $risk_asset,RiskAssetService $s):JsonResponse{$d=$r->validate(['version'=>'required|integer|min:1','display_name'=>'sometimes|string|max:160','external_reference'=>'sometimes|nullable|string|max:100','facts'=>'sometimes|array','status'=>'sometimes|in:ACTIVE,ARCHIVED']);$v=(int)$d['version'];unset($d['version']);return response()->json(['data'=>$s->update($this->scoped($risk_asset),$v,$d,$r->user())]);}
}
