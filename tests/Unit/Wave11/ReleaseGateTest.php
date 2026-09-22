<?php
use App\Domain\Release\ReleaseGate;

it('requires all production assurance gates', function (): void {
    expect(array_map(fn($gate)=>$gate->value,ReleaseGate::cases()))->toBe([
        'SECURITY','PERFORMANCE','ACCESSIBILITY','DISASTER_RECOVERY','UAT','OPENAPI','DATA_MIGRATION'
    ]);
});
