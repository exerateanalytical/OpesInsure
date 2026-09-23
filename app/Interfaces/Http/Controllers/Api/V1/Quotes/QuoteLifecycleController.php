<?php
declare(strict_types=1);
namespace App\Interfaces\Http\Controllers\Api\V1\Quotes;
use App\Application\Quotes\QuoteService;
use App\Domain\Tenancy\TenantContext;
use App\Models\{Quote,QuoteOffer};
use Illuminate\Http\{JsonResponse,Request};
final class QuoteLifecycleController
{
 private function quote(string $id):Quote{return Quote::where('tenant_id',app(TenantContext::class)->id())->findOrFail($id);}
 public function show(string $quote):JsonResponse{$q=$this->quote($quote);return response()->json(['data'=>['quote'=>$q,'offers'=>$q->offers()->with(['carrier.party','product'])->orderBy('comparison_rank')->get()]]);}
 public function rate(Request $r,string $quote,QuoteService $s):JsonResponse{$q=$s->rate($this->quote($quote),$r->user());return response()->json(['data'=>['quote'=>$q,'offers'=>$q->offers()->with(['carrier.party','product'])->orderBy('comparison_rank')->get()]]);}
 public function accept(Request $r,string $quote,string $offer,QuoteService $s):JsonResponse{$q=$this->quote($quote);$s->accept($q,QuoteOffer::findOrFail($offer),$r->user());return response()->json(['data'=>['quote_id'=>$q->id,'offer_id'=>$offer,'status'=>'ACCEPTED']]);}
}
