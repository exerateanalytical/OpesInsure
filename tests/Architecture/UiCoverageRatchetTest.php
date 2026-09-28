<?php

/**
 * Ratchet: desktop/web coverage of state-changing /api/v1 actions (php artisan ui:coverage) may only go up.
 * When coverage improves, raise the floors below to the new numbers. See docs/UI_COVERAGE_2026-09-27.md.
 */

use Illuminate\Support\Facades\Artisan;

const UI_COVERAGE_FLOOR_PCT = 44.3;
const UI_RELEVANT_COVERAGE_FLOOR_PCT = 45.2;

test('ui:coverage does not drop below the recorded floor', function () {
    expect(Artisan::call('ui:coverage', ['--no-write' => true]))->toBe(0);
    $out = Artisan::output();
    expect(preg_match('/coverage_pct=([\d.]+) ui_coverage_pct=([\d.]+)/', $out, $m))->toBe(1);
    expect((float) $m[1])->toBeGreaterThanOrEqual(UI_COVERAGE_FLOOR_PCT)
        ->and((float) $m[2])->toBeGreaterThanOrEqual(UI_RELEVANT_COVERAGE_FLOOR_PCT);
});
