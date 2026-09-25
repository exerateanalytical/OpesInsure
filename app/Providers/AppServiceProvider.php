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
        Passport::tokensCan([
            'quotes.read' => 'Read quote requests and offers',
            'quotes.write' => 'Submit quote requests',
            'policies.read' => 'Read policy and certificate data',
            'policies.write' => 'Submit issuance, endorsement and cancellation requests',
            'claims.read' => 'Read claim status',
            'claims.write' => 'Submit FNOL and claim updates',
            'settlements.read' => 'Read settlement and bordereau data',
            'webhooks.manage' => 'Manage webhook subscriptions',
        ]);
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
     * Owner decision D2 (canonical UI handoff): the Wave10 placeholder shell is
     * retired. /portal/{portal} now redirects to the panel that owns that
     * experience; customer and agent experiences are the mobile app.
     */
    private function registerPortalShellRoute(): void
    {
        Route::middleware(['web'])
            ->get('/portal/{portal}', function (string $portal) {
                $portal = strtoupper($portal);
                abort_unless(in_array($portal, PortalWorkspaceService::PORTALS, true), 404);

                return redirect(self::PORTAL_REDIRECTS[$portal], 301);
            })
            ->name('portal.dashboard');
    }

    /** @var array<string, string> Wave10 portal code => canonical web entry point. */
    public const PORTAL_REDIRECTS = ['ADMIN' => '/admin', 'BROKER' => '/broker', 'CARRIER' => '/insurer', 'AGENT' => '/download', 'CUSTOMER' => '/download'];

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
