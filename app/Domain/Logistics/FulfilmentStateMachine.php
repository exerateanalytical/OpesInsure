<?php
namespace App\Domain\Logistics;use DomainException;
final class FulfilmentStateMachine{private const T=['CREATED'=>['READY_FOR_PICKUP','CANCELLED'],'READY_FOR_PICKUP'=>['ASSIGNED','CANCELLED'],'ASSIGNED'=>['PICKED_UP','CANCELLED'],'PICKED_UP'=>['IN_TRANSIT'],'IN_TRANSIT'=>['DELIVERED','FAILED_ATTEMPT'],'FAILED_ATTEMPT'=>['ASSIGNED','RETURNING'],'RETURNING'=>['RETURNED'],'DELIVERED'=>[],'RETURNED'=>[],'CANCELLED'=>[]];public function assert(string$f,string$t):void{if(!in_array($t,self::T[$f]??[],true))throw new DomainException("Invalid fulfilment transition from {$f} to {$t}.");}}
