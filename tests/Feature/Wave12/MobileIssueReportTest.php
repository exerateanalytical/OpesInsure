<?php

declare(strict_types=1);

use App\Models\MobileIssueReport;
use Database\Seeders\MobileOAuthClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(MobileOAuthClientSeeder::class));

require_once __DIR__.'/Concerns/mobile_auth_helpers.php';

it('accepts a report with no authentication and stores it anonymously', function () {
    $response = $this->postJson('/api/v1/mobile/issue-reports', [
        'route' => '/agent/clients/new',
        'note' => 'Tapping Create protected client while offline jumped me to Sync Centre with no warning.',
        'platform' => 'android',
        'app_version' => '1.4.0',
    ]);

    $response->assertStatus(201);
    expect(MobileIssueReport::count())->toBe(1);

    $report = MobileIssueReport::first();
    expect($report->route)->toBe('/agent/clients/new');
    expect($report->user_id)->toBeNull();
    expect($report->status)->toBe('OPEN');
});

it('attributes a report to the signed-in user when a bearer token is present', function () {
    $user = makeMobileTestUser();
    $token = $user->createToken('test-device')->accessToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/mobile/issue-reports', [
            'route' => '/welcome',
            'note' => 'The splash carousel does not swipe on a small screen.',
        ]);

    $response->assertStatus(201);
    expect(MobileIssueReport::first()->user_id)->toBe($user->id);
});

it('rejects a report without a note', function () {
    $response = $this->postJson('/api/v1/mobile/issue-reports', ['route' => '/welcome']);

    $response->assertStatus(422);
    expect(MobileIssueReport::count())->toBe(0);
});
