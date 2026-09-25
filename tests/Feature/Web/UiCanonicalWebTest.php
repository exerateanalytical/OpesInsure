<?php

declare(strict_types=1);

/**
 * Canonical UI handoff (web): REQ-UI-001 / REQ-UI-002 extensions.
 * Shell tokens + locale, shared component states, insurance components, global error pages, public verification.
 */

use App\Application\WebExperiences\{FailureState, Money};
use App\Models\{Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function uiTenant(string $type = 'CARRIER'): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'UI '.$type.' '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function uiUser(Tenant $t, string $role, string $locale = 'en'): User
{
    $u = User::factory()->create(['status' => 'ACTIVE', 'locale' => $locale]);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    $r = Role::firstOrCreate(['tenant_id' => $t->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => ['*'], 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

test('REQ-UI-001 canonical tokens: one palette, blue #1769E0 interactive, Inter Variable, 3px blue focus', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));
    expect($css)->toContain('--oi-blue-600:#1769E0')->toContain('--oi-gold-500:#D99100')->toContain("'Inter Variable'")
        ->toContain('outline:var(--oi-focus-width) solid var(--oi-blue-600)')->not->toContain("font-family:'Manrope")->not->toContain('--oi-blue-650');
    $palette = \App\Providers\Filament\AdminPanelProvider::v3ColorPalette();
    expect($palette['primary'][600])->toBe('#1769E0')->and($palette['danger'][600])->toBe('#C9363E')->and($palette['success'][600])->toBe('#07855B');
});

test('REQ-UI-001 panels are bilingual: login pages switch EN/FR and remember the choice', function () {
    foreach (['admin', 'insurer', 'broker'] as $panel) {
        $this->get("/{$panel}/login?lang=fr")->assertOk()->assertSee('lang="fr"', false)->assertSee('data-testid="language-switch"', false);
        $this->get("/{$panel}/login")->assertOk()->assertHeader('Content-Language', 'fr');
        $this->get("/{$panel}/login?lang=en")->assertOk()->assertHeader('Content-Language', 'en');
    }
});

test('REQ-UI-001 signed-in user locale drives the portal language; tenant boundaries unchanged', function () {
    $carrier = uiTenant('CARRIER');
    $fr = uiUser($carrier, 'CARRIER_ADMIN', 'fr');
    $this->actingAs($fr)->get('/insurer')->assertOk()->assertSee(__('web_experience.metrics.active_policies', [], 'fr'))->assertHeader('Content-Language', 'fr');
    $this->actingAs($fr)->get('/broker')->assertForbidden()->assertSee('data-error="403"', false);
});

test('REQ-UI-002 every component state has explicit EN/FR copy, a tone and an icon', function () {
    foreach (FailureState::cases() as $s) {
        foreach (['en', 'fr'] as $l) {
            app()->setLocale($l);
            expect($s->title())->not->toStartWith('web_experience.')->and($s->message())->not->toStartWith('web_experience.');
        }
        expect(['danger', 'warning', 'info', 'gray'])->toContain($s->tone())->and($s->icon())->toStartWith('lucide-');
    }
    app()->setLocale('en');
    $html = view('filament.shared.failure-states', ['states' => [FailureState::OfflineQueued, FailureState::ClaimPaymentFailed]])->render();
    expect($html)->toContain('role="status"')->toContain('role="alert"')->toContain('<svg');
});

test('REQ-UI-002 status badge = icon + label, stable code kept; record tables become labelled cards', function () {
    $b = view('filament.shared.status-badge', ['status' => 'PAID_PENDING_ISSUANCE'])->render();
    expect($b)->toContain('data-status="PAID_PENDING_ISSUANCE"')->toContain('<svg')->toContain('Paid pending issuance');
    $f = view('filament.shared.financial-panel', ['rows' => [['label' => 'Premium payment', 'amount' => '1 000 XAF', 'status' => 'SUCCEEDED', 'source' => null, 'payer' => null, 'payee' => null, 'reference' => 'R1', 'reconciliation' => null, 'journal' => null]]])->render();
    expect($f)->toContain('class="oi-records"')->toContain('data-label="'.__('web_experience.financial.amount').'"');
    expect(view('filament.shared.financial-panel', ['rows' => []])->render())->toContain(__('web_experience.financial.empty'));
});

test('REQ-UI-002 FCFA display keeps amount and currency together, locale grouping; stable format unchanged', function () {
    expect(Money::display(123456700, 'XAF', 'en'))->toBe("1,234,567\u{00A0}FCFA")
        ->and(Money::display(123456700, 'XAF', 'fr'))->toBe("1\u{202F}234\u{202F}567\u{00A0}FCFA")
        ->and(Money::format(1234500, 'XAF'))->toBe('12 345 XAF');
});

test('REQ-UI-002 financial breakdown lists distinct labelled lines; payment state panel is explicit and recoverable', function () {
    $html = view('filament.shared.financial-breakdown', ['lines' => ['gross_premium' => 5000000, 'platform_fee' => 100000, 'processing_fee' => null, 'commission' => 500000, 'carrier_settlement' => 4500000], 'total' => 5100000, 'currency' => 'XAF'])->render();
    foreach (['gross_premium', 'platform_fee', 'processing_fee', 'commission', 'carrier_settlement', 'total'] as $k) {
        expect($html)->toContain('data-line="'.$k.'"');
    }
    expect($html)->toContain(__('web_experience.breakdown.pending'));
    $p = view('filament.shared.payment-state', ['payment' => ['status' => 'PENDING_CUSTOMER', 'state' => 'EXPIRED', 'network' => 'MTN_MOMO', 'phone' => '+237670000000', 'retry_url' => '/retry', 'lines' => ['gross_premium' => 100]]])->render();
    expect($p)->toContain('data-status="PENDING_CUSTOMER"')->toContain(__('web_experience.payment.states.EXPIRED'))->toContain('/retry')->toContain('MTN_MOMO')->toContain('+237670000000');
});

test('REQ-UI-002 approval panel shows maker/checker/change/before-after and explains disabled self-approval', function () {
    $html = view('filament.shared.approval-panel', ['approval' => ['status' => 'PENDING', 'change' => 'claim.payment.approve · claim', 'maker' => 'Ada', 'checker' => null, 'requested_at' => '2026-09-25 10:00', 'decided_at' => null, 'amount' => null, 'reason' => 'Loss', 'evidence' => ['DOC-1'], 'diff' => [['field' => 'amount', 'before' => '1', 'after' => '2']], 'self_approval' => true, 'blockers' => []]])->render();
    expect($html)->toContain('Ada')->toContain(__('web_experience.approval.awaiting_checker'))->toContain('DOC-1')->toContain('oi-diff__before')->toContain('data-testid="self-approval-reason"');
});

test('REQ-UI-009 global error pages are branded, bilingual and give a recovery action', function () {
    $this->get('/definitely-missing-page')->assertNotFound()->assertSee('data-error="404"', false)->assertSee(__('web_experience.errors.404.title', [], 'en'));
    $this->withHeader('Accept-Language', 'fr')->get('/definitely-missing-page')->assertNotFound()->assertSee(__('web_experience.errors.404.title', [], 'fr'));
    foreach (['403', '409', '419', '422', '429', '500', '503'] as $code) {
        $html = view('errors.'.$code)->render();
        expect($html)->toContain('data-error="'.$code.'"')->toContain('class="btn btn--primary"')->toContain('lang=');
    }
});

test('REQ-UI-009 public verification: canonical colours and SVG status icons, no legacy teal', function () {
    $this->get('/verify?ref=NOPE&t=x')->assertOk()->assertSee('data-result="not_found"', false)->assertSee('<svg', false)->assertDontSee('#0f766e', false);
});
