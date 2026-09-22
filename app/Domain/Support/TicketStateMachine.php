<?php
namespace App\Domain\Support;use DomainException;
final class TicketStateMachine{private const T=['OPEN'=>['TRIAGED','CANCELLED'],'TRIAGED'=>['IN_PROGRESS','ESCALATED'],'IN_PROGRESS'=>['WAITING_CUSTOMER','RESOLVED','ESCALATED'],'WAITING_CUSTOMER'=>['IN_PROGRESS','RESOLVED'],'ESCALATED'=>['IN_PROGRESS','RESOLVED'],'RESOLVED'=>['CLOSED','REOPENED'],'REOPENED'=>['IN_PROGRESS'],'CLOSED'=>[]];public function assert(string$f,string$t):void{if(!in_array($t,self::T[$f]??[],true))throw new DomainException("Invalid ticket transition from {$f} to {$t}.");}}
