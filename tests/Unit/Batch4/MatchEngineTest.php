<?php
use App\Domain\Reconciliation\MatchEngine;
test('reconciliation requires reference currency and amount equality',function(){$e=new MatchEngine;expect($e->decide(1000,1000,'XAF','XAF',true)->status)->toBe('MATCHED')->and($e->decide(1000,900,'XAF','XAF',true)->exceptionCode)->toBe('AMOUNT_MISMATCH')->and($e->decide(1000,1000,'XAF','EUR',true)->exceptionCode)->toBe('CURRENCY_MISMATCH');});
