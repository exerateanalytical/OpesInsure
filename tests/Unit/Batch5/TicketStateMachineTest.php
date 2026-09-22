<?php
use App\Domain\Support\TicketStateMachine;
test('resolved ticket may close',function(){(new TicketStateMachine)->assert('RESOLVED','CLOSED');expect(true)->toBeTrue();});
test('closed ticket cannot be silently reopened',function(){(new TicketStateMachine)->assert('CLOSED','REOPENED');})->throws(DomainException::class);
