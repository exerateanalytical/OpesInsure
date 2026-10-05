<?php

declare(strict_types=1);

/**
 * Role narrowing 2026-09-30 (owner rule: every user sees exactly what their RBAC permissions allow).
 * CLAIMS_MANAGER / FINANCE_MANAGER / FINANCE_ADMIN / COMPLIANCE_ADMIN no longer hold '*': a carrier-linked claims
 * manager sees only claims sections in /insurer, a finance manager only finance; the rest answers 403.
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\{Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function narrowTenant(): Tenant
{
    return Tenant::create(['type' => 'CARRIER', 'legal_name' => 'Narrow '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function narrowUser(Tenant $tenant, string $role, ?string $carrierId): User
{
    $user = User::factory()->create(['status' => 'ACTIVE', 'locale' => 'en']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

/** @return list<string> sidebar hrefs of the panel */
function narrowSidebar(string $html, string $panel = 'insurer'): array
{
    preg_match_all('#<a\b[^>]*fi-sidebar-item-btn[^>]*href="(?:https?://[^/"]+)?(/'.$panel.'(?:/[^"?\#]*)?)"|<a\b[^>]*href="(?:https?://[^/"]+)?(/'.$panel.'(?:/[^"?\#]*)?)"[^>]*fi-sidebar-item-btn#', $html, $m);

    return array_values(array_unique(array_filter([...$m[1], ...$m[2]])));
}

/** Paths a claims-only / finance-only user must never reach (staff, letterhead, products, tariffs, underwriting, …). */
const NARROW_CLAIMS_FORBIDDEN = ['/insurer/memberships', '/insurer/invitations', '/insurer/letterhead', '/insurer/insurance-products', '/insurer/tariff-versions',
    '/insurer/underwriting-cases', '/insurer/risk-transfer/treaties', '/insurer/journals', '/insurer/cashier-sessions', '/insurer/bordereaux',
    '/insurer/carrier-settlements', '/insurer/policy-issuances', '/insurer/sticker-batches', '/insurer/kyc', '/insurer/quotes'];

const NARROW_FINANCE_FORBIDDEN = ['/admin/claims', '/admin/memberships', '/admin/invitations', '/admin/insurance-products', '/admin/tariff-versions',
    '/admin/underwriting-cases', '/admin/sticker-batches', '/admin/kyc', '/admin/health', '/admin/compliance-cases', '/admin/tenants'];

it('no business role except the platform administrators defaults to the wildcard', function () {
    foreach (RoleCatalogue::codes() as $code) {
        if (in_array($code, ['SYSTEM_ADMIN', 'PLATFORM_ADMIN'], true)) {
            expect(RoleCatalogue::defaultPermissions($code))->toContain('*');

            continue;
        }
        expect(RoleCatalogue::defaultPermissions($code))->not->toContain('*', "{$code} still defaults to '*'");
    }
    $claims = RoleCatalogue::defaultPermissions('CLAIMS_MANAGER');
    expect($claims)->toContain('claims.view', 'claims.reserve.approve', 'claims.decision.approve', 'claims.payment.approve', 'claims.recovery', 'claims.experts.assign', 'claims.evidence.manage', 'policies.read')
        ->not->toContain('identity.invite', 'documents.letterheads.manage', 'ledger.post', 'stickers.view', 'kyc.view', 'underwriting.decide', 'policies.issuance_queue.view', 'cashier.sessions.view', 'bordereaux.view');
    expect(RoleCatalogue::defaultPermissions('FINANCE_MANAGER'))->toContain('ledger.post', 'settlement.approve', 'payout.approve', 'refund.approve')
        ->not->toContain('claims.view', 'identity.invite', 'kyc.view', 'underwriting.decide', 'catalogue.view');
    expect(RoleCatalogue::defaultPermissions('COMPLIANCE_ADMIN'))->toContain('kyc.decide', 'aml.screening.run', 'compliance.cases.create')
        ->not->toContain('ledger.post', 'claims.payment.approve', 'underwriting.decide');
});

it('a carrier-linked claims manager sees only claims sections in /insurer; the rest answers 403', function () {
    $tenant = narrowTenant();
    $chain = makeMobileFinanceProposalChain($tenant);
    $user = narrowUser($tenant, 'CLAIMS_MANAGER', $chain['carrier']->id);

    $home = $this->actingAs($user)->get('/insurer')->assertOk();
    $nav = narrowSidebar($home->getContent());
    if (getenv('NARROW_DUMP')) { fwrite(STDERR, "CLAIMS_MANAGER nav: ".implode(' ', $nav)."\n"); }
    expect($nav)->toContain('/insurer/claims');
    foreach (NARROW_CLAIMS_FORBIDDEN as $p) {
        expect(collect($nav)->contains(fn ($h) => str_starts_with($h, $p)))->toBeFalse("claims manager nav shows {$p}");
    }
    foreach ($nav as $href) {
        expect($this->get($href)->status())->toBe(200, "{$href} behind a visible nav item");
    }
    foreach (['/insurer/memberships', '/insurer/invitations', '/insurer/insurance-products', '/insurer/tariff-versions', '/insurer/journals', '/insurer/policy-issuances', '/insurer/sticker-batches', '/insurer/underwriting-cases', '/insurer/bordereaux', '/insurer/carrier-settlements'] as $p) {
        expect($this->get($p)->status())->toBe(403, "claims manager reached {$p}");
    }
});

it('a finance manager sees only finance sections; claims, staff, products and underwriting answer 403', function () {
    $tenant = narrowTenant();
    $chain = makeMobileFinanceProposalChain($tenant);
    $user = narrowUser($tenant, 'FINANCE_MANAGER', null);

    $home = $this->actingAs($user)->get('/admin')->assertOk();
    $nav = narrowSidebar($home->getContent(), 'admin');
    if (getenv('NARROW_DUMP')) { fwrite(STDERR, "FINANCE_MANAGER nav: ".implode(' ', $nav)."
"); }
    expect(collect($nav)->contains(fn ($h) => str_starts_with($h, '/admin/finance/period-closing')))->toBeTrue('finance manager has no period closing');
    foreach (NARROW_FINANCE_FORBIDDEN as $p) {
        expect(collect($nav)->contains(fn ($h) => str_starts_with($h, $p)))->toBeFalse("finance manager nav shows {$p}");
    }
    foreach ($nav as $href) {
        expect($this->get($href)->status())->toBe(200, "{$href} behind a visible nav item");
    }
    foreach (['/admin/claims', '/admin/memberships', '/admin/insurance-products', '/admin/underwriting-cases', '/admin/kyc'] as $p) {
        expect($this->get($p)->status())->toBeIn([403, 404], "finance manager reached {$p}");
    }
});

it('the narrowing migration strips the wildcard from stored roles of the narrowed codes, idempotently and audited', function () {
    $tenant = narrowTenant();
    $ids = [];
    foreach ([...RoleCatalogue::NARROWED_FROM_WILDCARD, 'SYSTEM_ADMIN'] as $code) {
        $ids[$code] = Role::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'code' => $code, 'permissions' => ['*', 'custom.extra'], 'is_system' => true])->id;
    }
    $migration = require database_path('migrations/2026_11_16_100001_rbac_narrow_wildcard_business_roles.php');
    $migration->up();
    $migration->up();

    foreach (RoleCatalogue::NARROWED_FROM_WILDCARD as $code) {
        $perms = Role::find($ids[$code])->permissions;
        expect($perms)->not->toContain('*')->toContain('custom.extra')
            ->and(array_diff(RoleCatalogue::defaultPermissions($code), $perms))->toBe([]);
    }
    expect(Role::find($ids['SYSTEM_ADMIN'])->permissions)->toContain('*');
    expect(DB::table('audit_log')->where('action', 'rbac.role.narrowed')->count())->toBe(count(RoleCatalogue::NARROWED_FROM_WILDCARD));
});
