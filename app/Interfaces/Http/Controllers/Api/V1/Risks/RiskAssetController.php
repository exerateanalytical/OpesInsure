<?php
declare(strict_types=1);
namespace App\Interfaces\Http\Controllers\Api\V1\Risks;
use App\Application\Identity\OwnershipScope;
use App\Application\Risks\RiskAssetService;
use App\Domain\Tenancy\TenantContext;
use App\Models\{Party,RiskAsset,Tenant};
use Illuminate\Http\{JsonResponse,Request};
final class RiskAssetController
{
 public function __construct(private OwnershipScope $own) {}
 /* Customers only ever resolve their own assets (audit A1). */
 private function scoped(string $id):RiskAsset{return $this->own->apply(RiskAsset::where('tenant_id',app(TenantContext::class)->id()),request()->user())->findOrFail($id);}
 public function index(Request $r):JsonResponse{$this->own->requireTenantWide($r->user(),'risk_assets.read');$q=$this->own->apply(RiskAsset::where('tenant_id',app(TenantContext::class)->id()),$r->user());if($r->filled('party_id'))$q->where('party_id',$r->string('party_id'));if($r->filled('type'))$q->where('type',$r->string('type'));return response()->json(['data'=>$q->latest()->paginate(25)]);}
 public function store(Request $r,RiskAssetService $s,TenantContext $c):JsonResponse{$d=$r->validate(['party_id'=>'required|uuid|exists:parties,id','type'=>['required',\Illuminate\Validation\Rule::in(\App\Application\Risks\RiskAssetTypes::codes())],'external_reference'=>'nullable|string|max:100','display_name'=>'required|string|max:160','facts'=>'required|array']);$this->own->assertOwnParty($r->user(),$d['party_id']);return response()->json(['data'=>$s->create(Tenant::findOrFail($c->id()),Party::findOrFail($d['party_id']),$d,$r->user())],201);}
 public function show(string $risk_asset):JsonResponse{return response()->json(['data'=>$this->scoped($risk_asset)]);}
 public function update(Request $r,string $risk_asset,RiskAssetService $s):JsonResponse{$asset=$this->scoped($risk_asset);$d=$r->validate(['version'=>'required|integer|min:1','display_name'=>'sometimes|string|max:160','external_reference'=>'sometimes|nullable|string|max:100','facts'=>'sometimes|array','status'=>'sometimes|in:ACTIVE,ARCHIVED']);$v=(int)$d['version'];unset($d['version']);return response()->json(['data'=>$s->update($asset,$v,$d,$r->user())]);}
}
