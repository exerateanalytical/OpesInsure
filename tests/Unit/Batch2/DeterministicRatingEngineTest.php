<?php
use App\Domain\Rating\DeterministicRatingEngine;

test('rating produces a transparent reproducible breakdown',function(){
    $result=(new DeterministicRatingEngine)->rate(['usage'=>'COMMERCIAL'],[
        'required_facts'=>['usage'],'base_premium_minor'=>10000,
        'factors'=>[['fact'=>'usage','equals'=>'COMMERCIAL','basis_points'=>2500,'code'=>'COMMERCIAL_USE']],
    ],['basis_points'=>1000],['fixed_minor'=>1500]);
    expect($result->basePremiumMinor)->toBe(12500)->and($result->taxMinor)->toBe(1250)->and($result->feeMinor)->toBe(1500)->and($result->totalMinor)->toBe(15250)->and($result->breakdown)->toHaveCount(4);
});

test('rating refuses missing mandatory risk facts',function(){(new DeterministicRatingEngine)->rate([],['required_facts'=>['usage'],'base_premium_minor'=>10000]);})->throws(DomainException::class);
