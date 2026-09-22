<?php
declare(strict_types=1);
namespace App\Application\Quotes;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Shared\CanonicalJson;
use App\Domain\Rating\DeterministicRatingEngine;
use App\Models\{InsuranceLine,InsuranceProduct,Quote,QuoteOffer,RiskAsset,TariffVersion,Tenant,User};
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class QuoteService
{
    public function __construct(private readonly DeterministicRatingEngine $engine,private readonly CanonicalJson $json,private readonly AuditWriter $audit,private readonly OutboxWriter $outbox){}

    public function submit(Tenant $tenant,string $partyId,array $data,?User $actor):Quote
    {
        return DB::transaction(function()use($tenant,$partyId,$data):Quote{
            if(!DB::table('tenant_customers')->where(['tenant_id'=>$tenant->id,'party_id'=>$partyId,'status'=>'ACTIVE'])->exists()) throw ValidationException::withMessages(['party_id'=>__('wave2.customer_not_active')]);
            $line=InsuranceLine::where(['code'=>$data['line_code'],'status'=>'ACTIVE'])->firstOrFail();
            foreach($line->risk_schema['required']??[] as $key) if(!array_key_exists($key,$data['risk_facts'])) throw ValidationException::withMessages(["risk_facts.$key"=>__('wave2.risk_fact_required')]);
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
            $offers=[];
            foreach($products as $product){
                if(!$this->eligible($quote->risk_facts,$product->eligibility_rules)) continue;
                $tariff=TariffVersion::where(['insurance_product_id'=>$product->id,'status'=>'APPROVED'])->whereDate('effective_from','<=',now())->where(fn($q)=>$q->whereNull('effective_until')->orWhereDate('effective_until','>=',now()))->latest('version')->first();
                if(!$tariff) continue;
                if($existing=QuoteOffer::where(['quote_id'=>$quote->id,'tariff_version_id'=>$tariff->id])->first()){$offers[]=$existing;continue;}
                $runId=(string)Str::uuid();$inputHash=$this->json->hash(['facts'=>$quote->risk_facts,'rules_hash'=>$tariff->rules_hash]);
                try{
                    $result=$this->engine->rate($quote->risk_facts,$tariff->rules,$this->activeRules('tax_levy_versions',['jurisdiction'=>'CM','line_code'=>$quote->line_code]),$this->activeRules('fee_schedule_versions',['tenant_id'=>$quote->tenant_id],true));
                    DB::table('rating_runs')->insert(['id'=>$runId,'tenant_id'=>$quote->tenant_id,'quote_id'=>$quote->id,'tariff_version_id'=>$tariff->id,'input_hash'=>$inputHash,'input_snapshot'=>$this->json->encode($quote->risk_facts),'output_snapshot'=>json_encode($result),'status'=>'SUCCEEDED','completed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
                    $validUntil=now()->addDays(7);if($quote->expires_at&&$quote->expires_at->lessThan($validUntil))$validUntil=$quote->expires_at;
                    $offers[]=QuoteOffer::create(['quote_id'=>$quote->id,'carrier_id'=>$product->carrier_id,'product_id'=>$product->id,'tariff_version_id'=>$tariff->id,'premium_minor'=>$result->basePremiumMinor,'tax_minor'=>$result->taxMinor,'fee_minor'=>$result->feeMinor,'total_minor'=>$result->totalMinor,'currency'=>$quote->currency,'status'=>'OFFERED','calculation_breakdown'=>$result->breakdown,'coverage_snapshot'=>['coverages'=>$product->coverageDefinitions->map(fn($c)=>['code'=>$c->code,'name'=>$c->name,'mandatory'=>$c->mandatory,'limit_minor'=>$c->pivot->default_limit_minor,'deductible_minor'=>$c->pivot->default_deductible_minor,'optional'=>$c->pivot->is_optional])->values(),'exclusions'=>$product->exclusions->map(fn($e)=>['code'=>$e->code,'name'=>$e->name])->values()],'valid_until'=>$validUntil]);
                }catch(DomainException $e){DB::table('rating_runs')->insert(['id'=>$runId,'tenant_id'=>$quote->tenant_id,'quote_id'=>$quote->id,'tariff_version_id'=>$tariff->id,'input_hash'=>$inputHash,'input_snapshot'=>$this->json->encode($quote->risk_facts),'status'=>'FAILED','failure_reason'=>$e->getMessage(),'completed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
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

    private function eligible(array $facts,array $rules):bool{foreach($rules['conditions']??[]as$c){$actual=data_get($facts,$c['fact']);$ok=match($c['operator']??'EQUALS'){'EQUALS'=>$actual===($c['value']??null),'IN'=>in_array($actual,(array)($c['value']??[]),true),'BETWEEN'=>is_numeric($actual)&&$actual>=($c['value'][0]??INF)&&$actual<=($c['value'][1]??-INF),default=>false};if(!$ok)return false;}return true;}
    private function activeRules(string $table,array $where,bool $global=false):array{$q=DB::table($table)->where('status','APPROVED')->whereDate('effective_from','<=',now())->where(fn($x)=>$x->whereNull('effective_until')->orWhereDate('effective_until','>=',now()));foreach($where as$k=>$v){if($global&&$k==='tenant_id')$q->where(fn($x)=>$x->where($k,$v)->orWhereNull($k));else$q->where($k,$v);}if($global)$q->orderByRaw('tenant_id IS NULL ASC');$row=$q->orderByDesc('version')->first();return$row?json_decode($row->rules,true,512,JSON_THROW_ON_ERROR):[];}
}
