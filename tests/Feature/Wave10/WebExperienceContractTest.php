<?php
use App\Application\WebExperiences\PortalWorkspaceService;

it('defines all five web insurance experiences', function (): void {
    expect(PortalWorkspaceService::PORTALS)->toBe(['ADMIN','BROKER','CARRIER','AGENT','CUSTOMER']);
});

it('keeps wave ten routes isolated for explicit integration', function (): void {
    $routes = file_get_contents(base_path('routes/wave10.php'));
    expect($routes)->toContain('marketplace/publications')->toContain('{portal}/dashboard');
});
