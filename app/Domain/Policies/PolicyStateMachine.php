<?php
namespace App\Domain\Policies;

use DomainException;

final class PolicyStateMachine
{
    private const TRANSITIONS=['PENDING_PAYMENT'=>['PAID_PENDING_ISSUANCE','CANCELLED'],'PAID_PENDING_ISSUANCE'=>['ACTIVE','CANCELLED'],'ACTIVE'=>['EXPIRING','ENDORSEMENT_PENDING','CANCELLATION_PENDING','SUSPENDED'],'EXPIRING'=>['EXPIRED','ACTIVE'],'ENDORSEMENT_PENDING'=>['ACTIVE','CANCELLED'],'CANCELLATION_PENDING'=>['CANCELLED','ACTIVE'],'SUSPENDED'=>['ACTIVE','CANCELLED'],'EXPIRED'=>['ACTIVE']];
    public function assert(string $from,string $to):void{if(!in_array($to,self::TRANSITIONS[$from]??[],true))throw new DomainException("Invalid policy transition from {$from} to {$to}.");}
}
