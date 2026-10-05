<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_auth_helpers.php';

// Launch fix 2026-09-29: the workspace "App issue reports" module leaked every tenant's free-text reports.

function wsIssueTenant(string $type, string $name): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => $name, 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
}

function wsIssueReport(?string $userId, string $note): void
{
    DB::table('mobile_issue_reports')->insert(['id' => (string) Str::uuid(), 'user_id' => $userId, 'route' => '/x', 'note' => $note, 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]);
}

it('shows a tenant manager only the issue reports filed by members of their own tenant', function () {
    $a = wsIssueTenant('BROKER', 'Tenant A');
    $b = wsIssueTenant('BROKER', 'Tenant B');
    $manager = makeMobileTenantStaffUser($a, '+237670019001', 'CLAIMS_MANAGER');
    $memberA = makeMobileTenantStaffUser($a, '+237670019002', 'CLAIMS_OFFICER');
    $memberB = makeMobileTenantStaffUser($b, '+237670019003', 'CLAIMS_OFFICER');
    // Since the 2026-09-30 narrowing the claims manager no longer holds '*': grant the module permission explicitly.
    $mr = \App\Models\Role::where('tenant_id', $a->id)->where('code', 'CLAIMS_MANAGER')->first();
    $mr->forceFill(['permissions' => [...$mr->permissions, \App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileWorkspaceController::ISSUE_REPORTS_PERMISSION]])->save();
    wsIssueReport($memberA->id, 'NOTE-A');
    wsIssueReport($memberB->id, 'NOTE-B');
    wsIssueReport(null, 'NOTE-ANON');

    Passport::actingAs($manager);
    $rows = collect($this->getJson('/api/v1/mobile/workspace/modules/issue-reports', tenantHeaderFor($a))->assertStatus(200)->json('data.rows'));

    expect($rows->pluck('Note')->all())->toBe(['NOTE-A']);
    $issues = collect($this->getJson('/api/v1/mobile/workspace/dashboard', tenantHeaderFor($a))->assertStatus(200)->json('data.metrics'))->firstWhere('key', 'issues');
    expect($issues['value'])->toBe('1');
});

it('hides the issue-reports module from staff without the operations permission', function () {
    $a = wsIssueTenant('BROKER', 'Tenant C');
    $officer = makeMobileTenantStaffUser($a, '+237670019011', 'CLAIMS_OFFICER');
    Passport::actingAs($officer);

    $this->getJson('/api/v1/mobile/workspace/modules/issue-reports', tenantHeaderFor($a))->assertStatus(403);
    $dashboard = $this->getJson('/api/v1/mobile/workspace/dashboard', tenantHeaderFor($a))->assertStatus(200);
    expect(collect($dashboard->json('data.modules'))->pluck('key')->all())->not->toContain('issue-reports')
        ->and(collect($dashboard->json('data.metrics'))->pluck('key')->all())->not->toContain('issues');
});

it('lets a platform administrator in the platform tenant see every report', function () {
    $platform = wsIssueTenant('PLATFORM', 'Platform');
    $other = wsIssueTenant('BROKER', 'Tenant D');
    $admin = makeMobileTenantStaffUser($platform, '+237670019021', 'PLATFORM_ADMIN');
    $member = makeMobileTenantStaffUser($other, '+237670019022', 'CLAIMS_OFFICER');
    wsIssueReport($member->id, 'NOTE-D');
    wsIssueReport(null, 'NOTE-ANON');
    Passport::actingAs($admin);

    $notes = collect($this->getJson('/api/v1/mobile/workspace/modules/issue-reports', tenantHeaderFor($platform))->assertStatus(200)->json('data.rows'))->pluck('Note')->sort()->values()->all();
    expect($notes)->toBe(['NOTE-ANON', 'NOTE-D']);
});
