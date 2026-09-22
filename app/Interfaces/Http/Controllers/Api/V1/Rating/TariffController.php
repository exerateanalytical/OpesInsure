<?php
declare(strict_types=1);
namespace App\Interfaces\Http\Controllers\Api\V1\Rating;
use App\Application\Rating\TariffGovernanceService;
use App\Models\{InsuranceProduct,TariffVersion};
use Illuminate\Http\{JsonResponse,Request};
final class TariffController
{
 public function store(Request $r,TariffGovernanceService $s):JsonResponse{$d=$r->validate(['insurance_product_id'=>'required|uuid|exists:insurance_products,id','effective_from'=>'required|date','effective_until'=>'nullable|date|after_or_equal:effective_from','input_schema'=>'required|array','rules'=>'required|array','regulatory_reference'=>'required|string|max:120']);return response()->json(['data'=>$s->create(InsuranceProduct::findOrFail($d['insurance_product_id']),$d,$r->user())],201);}
 public function submit(Request $r,string $tariff,TariffGovernanceService $s):JsonResponse{$d=$r->validate(['notes'=>'required|string|min:10|max:2000']);return response()->json(['data'=>$s->submit(TariffVersion::findOrFail($tariff),$r->user(),$d['notes'])]);}
 public function approve(Request $r,string $tariff,TariffGovernanceService $s):JsonResponse{$d=$r->validate(['reason'=>'required|string|min:20|max:2000']);return response()->json(['data'=>$s->approve(TariffVersion::findOrFail($tariff),$r->user(),$d['reason'])]);}
}
