<?php
namespace App\Domain\Claims;
use DomainException;
/**
 * @deprecated REQ-DUP-006: frozen legacy table. The single runtime claim machine is ClaimMachine (via
 * App\Application\Claims\ClaimTransitions). Kept only because the REQ-WFL-001 parity tests pin this table;
 * no application code may use it (tests/Feature/Batch11/ClaimMachine enforces that).
 */
final class ClaimLifecycle
{
    private const TRANSITIONS=[
        'DRAFT'=>['SUBMITTED'],'SUBMITTED'=>['ACKNOWLEDGED'],'ACKNOWLEDGED'=>['EVIDENCE_PENDING','ASSESSMENT'],
        'EVIDENCE_PENDING'=>['ASSESSMENT'],'ASSESSMENT'=>['CARRIER_REVIEW'],'CARRIER_REVIEW'=>['APPROVED','PARTIALLY_APPROVED','DECLINED'],
        'APPROVED'=>['PAYMENT_PENDING','CLOSED'],'PARTIALLY_APPROVED'=>['PAYMENT_PENDING','DISPUTED'],'DECLINED'=>['DISPUTED','CLOSED'],
        'PAYMENT_PENDING'=>['PAID'],'PAID'=>['CLOSED'],'DISPUTED'=>['CARRIER_REVIEW','CLOSED'],'CLOSED'=>['REOPENED'],'REOPENED'=>['ASSESSMENT'],
    ];
    public function assert(string$from,string$to):void{if(!in_array($to,self::TRANSITIONS[$from]??[],true))throw new DomainException("Invalid claim transition from {$from} to {$to}.");}
}
