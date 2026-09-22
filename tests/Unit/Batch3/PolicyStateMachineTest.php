<?php
use App\Domain\Policies\PolicyStateMachine;

test('active policy may enter endorsement review',function(){(new PolicyStateMachine)->assert('ACTIVE','ENDORSEMENT_PENDING');expect(true)->toBeTrue();});
test('expired policy cannot jump directly to cancellation pending',function(){(new PolicyStateMachine)->assert('EXPIRED','CANCELLATION_PENDING');})->throws(DomainException::class);
