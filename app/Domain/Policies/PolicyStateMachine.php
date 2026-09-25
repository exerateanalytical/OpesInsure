<?php
namespace App\Domain\Policies;

use DomainException;

/**
 * Stored (operational) policy statuses + their transitions. REQ-POL-001: the
 * blueprint canonical machine (ISSUANCE_PENDING → ISSUED → ACTIVE → EXPIRED;
 * AMENDED, SUSPENDED, CANCELLED, RENEWED) is exposed as a read-side mapping —
 * stored statuses are never renamed (same pattern as ProposalMachine::blueprintState).
 */
final class PolicyStateMachine
{
    public const STATES = ['PENDING_PAYMENT','PAID_PENDING_ISSUANCE','ACTIVE','EXPIRING','ENDORSEMENT_PENDING','CANCELLATION_PENDING','SUSPENDED','EXPIRED','LAPSED','CANCELLED'];

    /** Blueprint canonical states (BP Part II; blueprint wins per ICE §0.8). */
    public const BLUEPRINT_STATES = ['ISSUANCE_PENDING','ISSUED','ACTIVE','AMENDED','SUSPENDED','CANCELLED','EXPIRED','RENEWED'];

    /** Context-free mapping stored → blueprint. */
    public const BLUEPRINT = [
        'PENDING_PAYMENT' => 'ISSUANCE_PENDING',
        'PAID_PENDING_ISSUANCE' => 'ISSUANCE_PENDING',
        'ACTIVE' => 'ACTIVE',
        'EXPIRING' => 'ACTIVE',
        'ENDORSEMENT_PENDING' => 'ACTIVE',
        'CANCELLATION_PENDING' => 'ACTIVE',
        'SUSPENDED' => 'SUSPENDED',
        'EXPIRED' => 'EXPIRED',
        'LAPSED' => 'EXPIRED',
        'CANCELLED' => 'CANCELLED',
    ];

    private const TRANSITIONS=['PENDING_PAYMENT'=>['PAID_PENDING_ISSUANCE','CANCELLED'],'PAID_PENDING_ISSUANCE'=>['ACTIVE','CANCELLED'],'ACTIVE'=>['EXPIRING','ENDORSEMENT_PENDING','CANCELLATION_PENDING','SUSPENDED'],'EXPIRING'=>['EXPIRED','ACTIVE'],'ENDORSEMENT_PENDING'=>['ACTIVE','CANCELLED'],'CANCELLATION_PENDING'=>['CANCELLED','ACTIVE'],'SUSPENDED'=>['ACTIVE','CANCELLED'],'EXPIRED'=>['ACTIVE','LAPSED'],'LAPSED'=>['ACTIVE']];

    public function assert(string $from,string $to):void{if(!in_array($to,self::TRANSITIONS[$from]??[],true))throw new DomainException("Invalid policy transition from {$from} to {$to}.");}

    public static function blueprintState(string $status): string
    {
        return self::BLUEPRINT[$status] ?? $status;
    }

    /**
     * Contextual blueprint state: an in-force policy whose cover has not started
     * is ISSUED; an in-force policy on version > 1 is AMENDED; an expired/lapsed
     * policy with an issued successor is RENEWED.
     */
    public static function blueprintStateFor(string $status, ?\DateTimeInterface $coverageStartsAt = null, int $version = 1, bool $hasSuccessor = false, ?\DateTimeInterface $now = null): string
    {
        $state = self::blueprintState($status);
        $now ??= new \DateTimeImmutable();
        if ($state === 'ACTIVE' && $coverageStartsAt !== null && $coverageStartsAt > $now) {
            return 'ISSUED';
        }
        if ($state === 'ACTIVE' && $version > 1) {
            return 'AMENDED';
        }
        if ($state === 'EXPIRED' && $hasSuccessor) {
            return 'RENEWED';
        }

        return $state;
    }
}
