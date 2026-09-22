<?php
use App\Domain\Customers\AttributionPolicy;

test('latest effective active attribution wins deterministically', function () {
    $policy=new AttributionPolicy; $at=new DateTimeImmutable('2026-09-20');
    $decision=$policy->decide([
        ['partner_id'=>'old','effective_from'=>new DateTimeImmutable('2025-01-01'),'effective_until'=>new DateTimeImmutable('2025-12-31'),'status'=>'ACTIVE'],
        ['partner_id'=>'current','effective_from'=>new DateTimeImmutable('2026-01-01'),'status'=>'ACTIVE'],
    ],$at,'v1');
    expect($decision->partnerId)->toBe('current')->and($decision->reason)->toBe('EFFECTIVE_ORIGIN');
});
