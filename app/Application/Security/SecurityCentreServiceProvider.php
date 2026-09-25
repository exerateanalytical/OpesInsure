<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Application\Security\Attestation\AppAttestVerifier;
use App\Application\Security\Attestation\DeviceAttestationService;
use App\Application\Security\Attestation\PlayIntegrityVerifier;
use App\Application\Security\Console\SecuritySweepCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Agent B7 — security centre (REQ-SEC-001/003/005, REQ-MOB-007 backend).
 * Routes: the "Agent B7" block in routes/api.php and the .well-known block in routes/web.php.
 */
final class SecurityCentreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DeviceAttestationService::class, fn ($app) => new DeviceAttestationService([
            'PLAY_INTEGRITY' => $app->make(PlayIntegrityVerifier::class),
            'APP_ATTEST' => $app->make(AppAttestVerifier::class),
        ]));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SecuritySweepCommand::class]);
            $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('security:sweep')->everyFifteenMinutes()->withoutOverlapping());
        }
    }
}
