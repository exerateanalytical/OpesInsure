<?php
use App\Domain\Logistics\FulfilmentStateMachine;use App\Domain\Support\TicketStateMachine;
it('enforces fulfilment custody order',function(){(new FulfilmentStateMachine)->assert('ASSIGNED','PICKED_UP');expect(fn()=>(new FulfilmentStateMachine)->assert('CREATED','DELIVERED'))->toThrow(DomainException::class);});
it('enforces complaint workflow',function(){(new TicketStateMachine)->assert('OPEN','TRIAGED');expect(fn()=>(new TicketStateMachine)->assert('OPEN','CLOSED'))->toThrow(DomainException::class);});
