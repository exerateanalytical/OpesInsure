<?php
use App\Domain\Claims\ClaimStateMachine;
test('submitted claim may be acknowledged',function(){(new ClaimStateMachine)->assert('SUBMITTED','ACKNOWLEDGED');expect(true)->toBeTrue();});
test('submitted claim cannot jump directly to paid',function(){(new ClaimStateMachine)->assert('SUBMITTED','PAID');})->throws(DomainException::class);
