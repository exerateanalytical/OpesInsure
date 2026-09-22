<?php
use App\Domain\Claims\ClaimLifecycle;
it('allows the governed happy path',function(){$m=new ClaimLifecycle;foreach([['DRAFT','SUBMITTED'],['SUBMITTED','ACKNOWLEDGED'],['ACKNOWLEDGED','ASSESSMENT'],['ASSESSMENT','CARRIER_REVIEW'],['CARRIER_REVIEW','APPROVED'],['APPROVED','PAYMENT_PENDING'],['PAYMENT_PENDING','PAID'],['PAID','CLOSED']]as[$from,$to])$m->assert($from,$to);expect(true)->toBeTrue();});
it('rejects bypassing assessment and carrier review',function(){(new ClaimLifecycle)->assert('SUBMITTED','APPROVED');})->throws(DomainException::class);
it('requires an explicit reopen before reassessment',function(){(new ClaimLifecycle)->assert('CLOSED','ASSESSMENT');})->throws(DomainException::class);
