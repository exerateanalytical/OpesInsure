<?php
declare(strict_types=1);
namespace App\Interfaces\Http\Controllers\Api\V1\Catalogue;
use App\Application\Catalogue\CatalogueService;
use App\Models\{InsuranceLine,InsuranceProduct};
use Illuminate\Http\{JsonResponse,Request};
final class CatalogueController
{
 public function lines():JsonResponse{return response()->json(['data'=>InsuranceLine::with(['coverages','exclusions'])->where('status','ACTIVE')->orderBy('code')->get()]);}
 /** Same rule as showProduct: without catalogue.view the list carries ACTIVE products only and no tariffs; ?code= finds one product (web /account/buy?product=). */
 public function products(Request $r):JsonResponse{$staff=(bool)$r->user()?->hasPermission('catalogue.view');$q=InsuranceProduct::with(array_values(array_filter(['carrier.party','coverageDefinitions','exclusions',$staff?'tariffs':null])));if(!$staff){$q->where('status','ACTIVE');}if($r->filled('code'))$q->where('code',$r->string('code'));if($r->filled('line_code'))$q->where('line_code',$r->string('line_code'));if($r->filled('status'))$q->where('status',$r->string('status'));return response()->json(['data'=>$q->latest()->paginate(25)]);}
 /** Security 2026-09-29: without catalogue.view, only an ACTIVE product and never its tariffs (drafts and rate tables are internal). */
 public function showProduct(Request $r,string $product):JsonResponse{$staff=(bool)$r->user()?->hasPermission('catalogue.view');$q=InsuranceProduct::with(array_values(array_filter(['carrier.party','coverageDefinitions','exclusions',$staff?'tariffs':null])));if(!$staff){$q->where('status','ACTIVE');}return response()->json(['data'=>$q->findOrFail($product)]);}
 public function storeLine(Request $r,CatalogueService $s):JsonResponse{$d=$r->validate(['code'=>'required|string|max:32|unique:insurance_lines,code','name'=>'required|array','description'=>'nullable|array','risk_schema'=>'required|array']);return response()->json(['data'=>$s->createLine($d,$r->user())],201);}
 public function storeCoverage(Request $r,string $line,CatalogueService $s):JsonResponse{$d=$r->validate(['code'=>'required|string|max:64','name'=>'required|array','description'=>'nullable|array','limit_type'=>'required|string|max:32','mandatory'=>'required|boolean']);return response()->json(['data'=>$s->createCoverage(InsuranceLine::findOrFail($line),$d,$r->user())],201);}
 public function storeExclusion(Request $r,string $line,CatalogueService $s):JsonResponse{$d=$r->validate(['code'=>'required|string|max:64','name'=>'required|array','description'=>'nullable|array']);return response()->json(['data'=>$s->createExclusion(InsuranceLine::findOrFail($line),$d,$r->user())],201);}
 public function storeProduct(Request $r,CatalogueService $s):JsonResponse{$d=$r->validate(['carrier_id'=>'required|uuid|exists:carriers,id','line_code'=>'required|string|max:32|exists:insurance_lines,code','code'=>'required|string|max:64','name'=>'required|string|max:160','effective_from'=>'required|date','effective_until'=>'nullable|date|after_or_equal:effective_from','coverage_ids'=>'required|array|min:1','coverage_ids.*'=>'uuid|distinct','exclusion_ids'=>'nullable|array','exclusion_ids.*'=>'uuid|distinct','eligibility_rules'=>'required|array','regulatory_reference'=>'nullable|string|max:120']);app(\App\Application\Identity\CarrierScopeResolver::class)->abortUnlessOwnCarrier($r->user(),$d['carrier_id'],app(\App\Domain\Tenancy\TenantContext::class)->id());return response()->json(['data'=>$s->createProduct($d,$r->user())],201);}
 public function submitProduct(Request $r,string $product,CatalogueService $s):JsonResponse{$d=$r->validate(['notes'=>'required|string|min:10|max:2000']);return response()->json(['data'=>$s->submit($this->carrierProduct($r,$product),$r->user(),$d['notes'])]);}
 public function publishProduct(Request $r,string $product,CatalogueService $s):JsonResponse{$d=$r->validate(['reason'=>'required|string|min:20|max:2000']);return response()->json(['data'=>$s->publish($this->carrierProduct($r,$product),$r->user(),$d['reason'])]);}
 /** S6: a carrier-scoped caller only touches its own insurer's products (404 otherwise). */
 private function carrierProduct(Request $r,string $id):InsuranceProduct{$p=InsuranceProduct::findOrFail($id);app(\App\Application\Identity\CarrierScopeResolver::class)->abortUnlessOwnCarrier($r->user(),$p->carrier_id,app(\App\Domain\Tenancy\TenantContext::class)->id());return $p;}
}
