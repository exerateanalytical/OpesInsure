<?php
namespace App\Domain\Rating;

use DomainException;

final class DeterministicRatingEngine
{
    public function rate(array $facts, array $rules, array $taxRules=[], array $feeRules=[]): RatingResult
    {
        foreach(($rules['required_facts']??[]) as $key) if(!array_key_exists($key,$facts)) throw new DomainException("Missing required risk fact: {$key}");
        $base=(int)($rules['base_premium_minor']??0); if($base<=0) throw new DomainException('Approved positive base premium is required.');
        $premium=$base; $breakdown=[['code'=>'BASE','amount_minor'=>$base]];
        foreach(($rules['factors']??[]) as $factor){$actual=data_get($facts,$factor['fact']);$operator=$factor['operator']??(array_key_exists('equals',$factor)?'EQUALS':null);$expected=$factor['value']??($factor['equals']??null);$matches=match($operator){'EQUALS'=>$actual===$expected,'IN'=>in_array($actual,(array)$expected,true),'BETWEEN'=>is_numeric($actual)&&isset($expected[0],$expected[1])&&$actual>=$expected[0]&&$actual<=$expected[1],default=>throw new DomainException('Unsupported rating factor operator.')};if($matches){$points=(int)$factor['basis_points'];if($points < -10000 || $points > 100000)throw new DomainException('Rating factor is outside the permitted range.');$delta=(int)round($premium*$points/10000);$premium+=$delta;$breakdown[]=['code'=>$factor['code'],'fact'=>$factor['fact'],'operator'=>$operator,'amount_minor'=>$delta];}}
        if($premium<=0) throw new DomainException('Calculated premium must remain positive.');
        $tax=(int)round($premium*((int)($taxRules['basis_points']??0))/10000); $fee=(int)($feeRules['fixed_minor']??0);
        return new RatingResult($premium,$tax,$fee,$premium+$tax+$fee,[...$breakdown,['code'=>'TAX','amount_minor'=>$tax],['code'=>'FEE','amount_minor'=>$fee]]);
    }
}
