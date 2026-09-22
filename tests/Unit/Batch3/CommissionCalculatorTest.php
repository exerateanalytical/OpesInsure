<?php
use App\Domain\Commissions\CommissionCalculator;

test('commission and holdback use exact minor units',function(){$x=(new CommissionCalculator)->calculate(100000,1500,1000);expect($x->grossMinor)->toBe(15000)->and($x->holdbackMinor)->toBe(1500)->and($x->netAccruedMinor)->toBe(13500);});
test('commission rejects rates above one hundred percent',function(){(new CommissionCalculator)->calculate(100000,10001,0);})->throws(DomainException::class);
