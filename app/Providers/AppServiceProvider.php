<?php

namespace App\Providers;

use App\Application\Documents\Adapters\ClamAvMalwareScanAdapter;
use App\Application\Documents\Adapters\FailClosedMalwareScanAdapter;
use App\Application\Documents\Adapters\LocalSignedUrlAdapter;
use App\Application\Documents\Adapters\MalwareScanAdapter;
use App\Application\Documents\Adapters\ManualReviewOcrAdapter;
use App\Application\Documents\Adapters\OcrAdapter;
use App\Application\Documents\Adapters\S3SignedUrlAdapter;
use App\Application\Documents\Adapters\SignedUrlAdapter;
use App\Application\WebExperiences\{PortalDashboardQuery, PortalWorkspaceService};
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Middleware\ResolveAdminPanelTenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);

        // Admin-editable platform settings (Filament "Platform settings").
        $this->app->singleton(\App\Application\Settings\PlatformSettings::class);

        // Admin SMTP settings take effect the moment anything first builds a
        // mailer (web request or queue worker) — never baked into config:cache.
        $this->app->resolving('mail.manager', fn () => $this->app->make(\App\Application\Settings\PlatformSettings::class)->applyMailConfig(purge: false));

        // Picks the real S3 adapter only when this app is actually configured
        // for cloud storage; today FILESYSTEM_DISK=local, so the local
        // signed-route adapter is what's active (see LocalSignedUrlAdapter).
        $this->app->bind(SignedUrlAdapter::class, fn () => config('filesystems.default') === 's3' ? new S3SignedUrlAdapter : new LocalSignedUrlAdapter);

        // Fails closed until CLAMAV_HOST is set — see FailClosedMalwareScanAdapter.
        $this->app->bind(MalwareScanAdapter::class, fn () => filled(config('services.clamav.host')) ? new ClamAvMalwareScanAdapter : new FailClosedMalwareScanAdapter);

        // No OCR/data-extraction provider exists anywhere in this app —
        // unlike MalwareScanAdapter there is no real second implementation
        // to switch to yet, so this always binds the honest placeholder.
        // See ManualReviewOcrAdapter and the KYC batch report.
        $this->app->bind(OcrAdapter::class, fn () => new ManualReviewOcrAdapter);
        // Batch 9-2: allocation engine → financial obligations (agent 9-1), guarded until that service exists.
        $this->app->bind(\App\Application\Finance\Allocations\ObligationGateway::class, \App\Application\Finance\Allocations\GuardedObligationGateway::class);
    }

    public function boot(): void
    {
        $this->registerPassportScopes();
        $this->registerMobileTokenExpiry();
        $this->registerPortalShellRoute();

        // deploy.sh runs `migrate --force` then `optimize`; hooking demo:seed
        // into optimize keeps demo data current on every deploy (no-op when
        // demo mode is off).
        // Official register (regulatory layer) runs on every deploy, demo mode or not, before demo data.
        $this->optimizes(optimize: 'opesinsure:seed-regulatory', key: 'regulatory-register');
        $this->optimizes(optimize: 'demo:seed', key: 'demo-seed');

        if ($this->app->environment('local')) {
            $this->registerLocalDemoLogin();
        }
    }

    /**
     * Scopes for partner-facing client-credentials tokens (see
     * IntegrationClientLifecycleService / AuthenticateIntegrationClient).
     * Kept identical to the list IntegrationController validates against —
     * a client can only ever be granted a token scope it's also permitted
     * to request.
     */
    private function registerPassportScopes(): void
    {
        Passport::tokensCan(\App\Application\Integrations\Developer\OAuthScopeCatalogue::descriptions());
    }

    /**
     * The only personal-access-token consumer today is MobileAuthService
     * (issues one after OTP verification) — nothing else in this app mints
     * personal access tokens, so a single global short expiry is safe.
     * Rotation/replay-family revocation for the mobile *refresh* token lives
     * in MobileAuthService/MobileRefreshToken, not here.
     */
    private function registerMobileTokenExpiry(): void
    {
        Passport::personalAccessTokensExpireIn(now()->addMinutes(30));
    }

    /**
     * Minimal, honest wiring for the wave10 portal shell: real dashboard
     * metrics via PortalDashboardQuery, no invented navigation/business
     * content. This is a placeholder for the separate design-system
     * implementation described in OPESINSURE_CLAUDE_UI_IMPLEMENTATION_HANDOFF.md,
     * not a finished portal experience.
     */
    private function registerPortalShellRoute(): void
    {
        Route::middleware(['web', 'auth', ResolveAdminPanelTenant::class])
            ->get('/portal/{portal}', function (string $portal, PortalDashboardQuery $query) {
                $portal = strtoupper($portal);
                abort_unless(in_array($portal, PortalWorkspaceService::PORTALS, true), 404);

                $data = $query->handle(app(TenantContext::class)->id(), $portal);
                $user = auth()->user();

                return view('wave10.portal', [
                    'title' => Str::title($portal) . ' Portal',
                    'eyebrow' => 'OpesInsure',
                    'initials' => Str::of($user->full_name)->explode(' ')->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode(''),
                    'navigation' => [['url' => '#', 'label' => __('wave10.priority_work'), 'icon' => '◧', 'active' => true]],
                    'metrics' => collect($data['metrics'])->map(fn ($count, $table) => [
                        'label' => Str::headline($table),
                        'value' => $count,
                        'context' => __('wave10.account'),
                    ])->values()->all(),
                    'actions' => [],
                    'slot' => 'Portal content design pending — see OPESINSURE_CLAUDE_UI_IMPLEMENTATION_HANDOFF.md.',
                ]);
            })
            ->name('portal.dashboard');
    }

    private function registerLocalDemoLogin(): void
    {
        $demoEmails = collect(DatabaseSeeder::DEMO_ACCOUNTS)->pluck('email');

        Route::middleware('web')->get('/admin/dev-login/{email}', function (string $email) use ($demoEmails) {
            abort_unless($demoEmails->contains($email), 404);

            Auth::guard('web')->login(User::where('email', $email)->firstOrFail());

            return redirect('/admin');
        })->name('dev-login');

        FilamentView::registerRenderHook(
            PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
            fn (): string => view('filament.auth.demo-accounts', ['accounts' => DatabaseSeeder::DEMO_ACCOUNTS])->render(),
        );
    }
}
