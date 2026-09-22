<?php
declare(strict_types=1);
use App\Domain\Rating\DeterministicRatingEngine;
it('applies equals in and between factors deterministically',function(){$rules=['base_premium_minor'=>10000,'factors'=>[['code'=>'usage','fact'=>'usage','operator'=>'EQUALS','value'=>'TAXI','basis_points'=>1000],['code'=>'zone','fact'=>'zone','operator'=>'IN','value'=>['A','B'],'basis_points'=>500],['code'=>'power','fact'=>'power','operator'=>'BETWEEN','value'=>[8,12],'basis_points'=>250]]];$r=(new DeterministicRatingEngine)->rate(['usage'=>'TAXI','zone'=>'A','power'=>10],$rules);expect($r->basePremiumMinor)->toBe(11839);});
