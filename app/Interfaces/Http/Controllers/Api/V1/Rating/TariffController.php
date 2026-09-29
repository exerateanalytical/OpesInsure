<?php
declare(strict_types=1);
namespace App\Interfaces\Http\Controllers\Api\V1\Rating;
use App\Application\Rating\TariffGovernanceService;
use App\Models\{InsuranceProduct,TariffVersion};
use Illuminate\Http\{JsonResponse,Request};
final class TariffController
{
 public function store(Request $r,TariffGovernanceService $s):JsonResponse{$d=$r->validate(['insurance_product_id'=>'required|uuid|exists:insurance_products,id','effective_from'=>'required|date','effective_until'=>'nullable|date|after_or_equal:effective_from','input_schema'=>'required|array','rules'=>'required|array','regulatory_reference'=>'required|string|max:120']);return response()->json(['data'=>$s->create($this->carrierProduct($r,$d['insurance_product_id']),$d,$r->user())],201);}
 public function submit(Request $r,string $tariff,TariffGovernanceService $s):JsonResponse{$d=$r->validate(['notes'=>'required|string|min:10|max:2000']);return response()->json(['data'=>$s->submit($this->carrierTariff($r,$tariff),$r->user(),$d['notes'])]);}
 public function approve(Request $r,string $tariff,TariffGovernanceService $s):JsonResponse{$d=$r->validate(['reason'=>'required|string|min:20|max:2000']);return response()->json(['data'=>$s->approve($this->carrierTariff($r,$tariff),$r->user(),$d['reason'])]);}
 /** S6: a carrier-scoped caller only touches its own insurer's products (404 otherwise). */
 private function carrierProduct(Request $r,string $id):InsuranceProduct{$p=InsuranceProduct::findOrFail($id);app(\App\Application\Identity\CarrierScopeResolver::class)->abortUnlessOwnCarrier($r->user(),$p->carrier_id,app(\App\Domain\Tenancy\TenantContext::class)->id());return $p;}
 /** S6: a carrier-scoped caller only touches its own insurer's tariffs (404 otherwise). */
 private function carrierTariff(Request $r,string $id):TariffVersion{$t=TariffVersion::with('product')->findOrFail($id);app(\App\Application\Identity\CarrierScopeResolver::class)->abortUnlessOwnCarrier($r->user(),$t->product?->carrier_id,app(\App\Domain\Tenancy\TenantContext::class)->id());return $t;}
}
