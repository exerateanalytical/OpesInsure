<?php
use App\Domain\Logistics\FulfilmentStateMachine;
test('delivery follows custody sequence',function(){(new FulfilmentStateMachine)->assert('IN_TRANSIT','DELIVERED');expect(true)->toBeTrue();});
test('created order cannot become delivered immediately',function(){(new FulfilmentStateMachine)->assert('CREATED','DELIVERED');})->throws(DomainException::class);
