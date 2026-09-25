<?php
declare(strict_types=1);
namespace App\Application\Quotes;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Shared\CanonicalJson;
use App\Application\Rating\RatingService;
use App\Application\Temporal\ReferenceInstant;
use App\Models\{InsuranceLine,InsuranceProduct,Quote,QuoteOffer,RiskAsset,TariffVersion,Tenant,User};
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class QuoteService
{
    public function __construct(private readonly RatingService $rating,private readonly CanonicalJson $json,private readonly AuditWriter $audit,private readonly OutboxWriter $outbox){}

    public function submit(Tenant $tenant,string $partyId,array $data,?User $actor):Quote
    {
        return DB::transaction(function()use($tenant,$partyId,$data,$actor):Quote{
            if(!DB::table('tenant_customers')->where(['tenant_id'=>$tenant->id,'party_id'=>$partyId,'status'=>'ACTIVE'])->exists()) throw ValidationException::withMessages(['party_id'=>__('wave2.customer_not_active')]);
            $line=InsuranceLine::where(['code'=>$data['line_code'],'status'=>'ACTIVE'])->firstOrFail();
            /* Master-data codes validated ("Other" filed for review) and legacy tariff facts derived (RiskFactsProcessor). */$data['risk_facts']=app(\App\Application\MasterData\RiskFactsProcessor::class)->process($line->code,$data['risk_facts'],$tenant->id,$actor?->id);
            // Vehicle master: the 28-value vehicle_usage feeds the tariff's usage_type until tariffs rate on it directly.
            if(strtoupper((string)$data['line_code'])==='MOTOR'){ \App\Application\Vehicles\MotorRiskSchema::validateVehicleFacts($data['risk_facts']); $data['risk_facts']=\App\Application\Vehicles\VehicleUsageMapper::withDerivedFacts($data['risk_facts']); }
            foreach($line->risk_schema['required']??[] as $key) if(!array_key_exists($key,$data['risk_facts'])) throw ValidationException::withMessages(["risk_facts.$key"=>__('wave2.risk_fact_required')]);
            // REQ-RUL-004: configurable QUOTE completeness gate (completeness rule sets; no rules = no-op).
            app(\App\Application\Rules\RuleEngine::class)->assertComplete('QUOTE',(string)$data['line_code'],null,$data['risk_facts'],null,$tenant->id);
            if(isset($data['risk_asset_id'])&&!RiskAsset::where(['id'=>$data['risk_asset_id'],'tenant_id'=>$tenant->id,'party_id'=>$partyId,'status'=>'ACTIVE'])->exists()) throw ValidationException::withMessages(['risk_asset_id'=>__('wave2.asset_ownership')]);
            $quote=Quote::create(['tenant_id'=>$tenant->id,'party_id'=>$partyId,'risk_asset_id'=>$data['risk_asset_id']??null,'line_code'=>$data['line_code'],'channel'=>$data['channel'],'status'=>'SUBMITTED','currency'=>'XAF','risk_facts'=>$data['risk_facts'],'submitted_at'=>now(),'expires_at'=>now()->addDays(7),'version'=>1]);
            $this->audit->record('quote.submitted','quote',$quote->id,['line_code'=>$quote->line_code]);
            $this->outbox->record('quote.submitted','quote',$quote->id,['quote_id'=>$quote->id,'tenant_id'=>$tenant->id]);
            return $quote;
        });
    }

    public function rate(Quote $quote,?User $actor):Quote
    {
        if(!in_array($quote->status,['SUBMITTED','REFERRED','OFFERED'],true)) throw ValidationException::withMessages(['status'=>__('wave2.quote_not_rateable')]);
        $products=InsuranceProduct::with(['carrier','coverageDefinitions','exclusions'])->where(['line_code'=>$quote->line_code,'status'=>'ACTIVE'])->whereHas('carrier',fn($q)=>$q->where('status','ACTIVE'))->whereDate('effective_from','<=',now())->where(fn($q)=>$q->whereNull('effective_until')->orWhereDate('effective_until','>=',now()))->get();
        if($products->isEmpty()) throw ValidationException::withMessages(['products'=>__('wave2.no_active_products')]);
        return DB::transaction(function()use($quote,$products):Quote{
            $offers=[];$at=ReferenceInstant::at(now(),(string)config('app.timezone','Africa/Douala'));
            foreach($products as $product){
                // REQ-RUL-003: structured eligibility with outcome + trace, logged in engine_evaluations (never a silent skip).
                if(!app(\App\Application\Rules\RuleEngine::class)->eligibility($product,$quote->risk_facts,null,['type'=>'quote','id'=>$quote->id],$quote->tenant_id)['outcome']->quotable()) continue;
                $tariff=$this->rating->tariffFor($product,$at);
                if(!$tariff) continue;
                if($existing=QuoteOffer::where(['quote_id'=>$quote->id,'tariff_version_id'=>$tariff->id])->first()){$offers[]=$existing;continue;}
                // REQ-RAT-001/004: one rating path (RatingService) — versions via the Temporal engine, snapshot in rating_runs.
                $run=$this->rating->rateQuote($quote,$tariff,$at);if(!($result=$run['pricing']))continue;
                $validUntil=now()->addDays(7);if($quote->expires_at&&$quote->expires_at->lessThan($validUntil))$validUntil=$quote->expires_at;
                $offer=QuoteOffer::create(['quote_id'=>$quote->id,'carrier_id'=>$product->carrier_id,'product_id'=>$product->id,'tariff_version_id'=>$tariff->id,'premium_minor'=>$result->netPremiumMinor,'tax_minor'=>$result->taxMinor,'fee_minor'=>$result->feeMinor,'total_minor'=>$result->totalMinor,'currency'=>$quote->currency,'status'=>'OFFERED','calculation_breakdown'=>$result->lines,'coverage_snapshot'=>['coverages'=>$product->coverageDefinitions->map(fn($c)=>['code'=>$c->code,'name'=>$c->name,'mandatory'=>$c->mandatory,'limit_minor'=>$c->pivot->default_limit_minor,'deductible_minor'=>$c->pivot->default_deductible_minor,'optional'=>$c->pivot->is_optional])->values(),'exclusions'=>$product->exclusions->map(fn($e)=>['code'=>$e->code,'name'=>$e->name])->values()],'valid_until'=>$validUntil]);$offer->forceFill(['rating_run_id'=>$run['run_id']])->save();$offers[]=$offer;
            }
            $offers=collect($offers)->sort(fn($a,$b)=>[$a->total_minor,-count($a->coverage_snapshot['coverages']??[])]<=>[$b->total_minor,-count($b->coverage_snapshot['coverages']??[])])->values();
            foreach($offers as $i=>$offer)$offer->update(['comparison_rank'=>$i+1,'ranking_reasons'=>$i===0?['LOWEST_TOTAL_THEN_COVERAGE']:['TOTAL_ASCENDING']]);
            $status=$offers->isNotEmpty()?'OFFERED':'REFERRED';$quote->update(['status'=>$status,'rated_at'=>now(),'comparison_context'=>['algorithm'=>'TOTAL_ASC_THEN_COVERAGE_DESC','currency'=>$quote->currency,'offer_count'=>$offers->count()]]);
            $this->audit->record('quote.rated','quote',$quote->id,['offers'=>$offers->count()]);$this->outbox->record('quote.rated','quote',$quote->id,['quote_id'=>$quote->id,'status'=>$status,'offer_count'=>$offers->count()]);return $quote->refresh();
        });
    }

    public function accept(Quote $quote,QuoteOffer $offer,?User $actor):Quote
    {
        if($offer->quote_id!==$quote->id||$offer->status!=='OFFERED')throw ValidationException::withMessages(['offer'=>__('wave2.offer_unavailable')]);
        if($offer->valid_until->isPast())throw ValidationException::withMessages(['offer'=>__('wave2.offer_expired')]);
        return DB::transaction(function()use($quote,$offer):Quote{$quote->offers()->whereKeyNot($offer->id)->where('status','OFFERED')->update(['status'=>'NOT_SELECTED']);$offer->update(['status'=>'ACCEPTED']);$quote->update(['status'=>'ACCEPTED']);$this->audit->record('quote.offer.accepted','quote_offer',$offer->id,['quote_id'=>$quote->id]);$this->outbox->record('quote.offer.accepted','quote_offer',$offer->id,['quote_id'=>$quote->id,'offer_id'=>$offer->id]);return $quote->refresh();});
    }

    public function cancel(Quote $quote,?User $actor):Quote
    {
        if(!in_array($quote->status,['SUBMITTED','REFERRED','OFFERED'],true))throw ValidationException::withMessages(['status'=>__('wave2.quote_not_cancellable')]);
        return DB::transaction(function()use($quote):Quote{$quote->update(['status'=>'CANCELLED']);$this->audit->record('quote.cancelled','quote',$quote->id,[]);$this->outbox->record('quote.cancelled','quote',$quote->id,['quote_id'=>$quote->id]);return $quote->refresh();});
    }

}
