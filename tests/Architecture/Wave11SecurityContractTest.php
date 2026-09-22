<?php
it('ships release security and recovery controls', function (): void {
    expect(config('release-assurance.security.block_severities'))->toBe(['CRITICAL','HIGH'])
        ->and(config('release-assurance.accessibility.standard'))->toBe('WCAG 2.2 AA')
        ->and(file_exists(base_path('docs/operations/WAVE_11_RELEASE_RUNBOOK.md')))->toBeTrue();
});
