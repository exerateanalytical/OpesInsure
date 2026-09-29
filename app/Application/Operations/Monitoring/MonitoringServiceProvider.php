<?php

declare(strict_types=1);

namespace App\Application\Operations\Monitoring;

use App\Application\Operations\Monitoring\Http\StatusController;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * S12 monitoring: /status JSON for uptime monitors, a database probe on Laravel's public /up, and the release id in
 * the admin panel footer. Exceptions are captured from bootstrap/app.php (ErrorEventRecorder); alerts run from
 * routes/console.php (ops:alerts every 5 minutes).
 */
final class MonitoringServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::get('/status', StatusController::class)->name('monitoring.status');

        // /up returns 500 when the database does not answer (Laravel renders the failure page for DiagnosingHealth).
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::select('select 1');
        });

        FilamentView::registerRenderHook(PanelsRenderHook::FOOTER, function (): string {
            if (Filament::getCurrentPanel()?->getId() !== 'admin') {
                return '';
            }

            return '<div class="opes-release-footer" style="text-align:center;font-size:.75rem;opacity:.6;padding:.75rem 0">'
                .e(__('monitoring.footer.release', ['release' => ReleaseId::current()])).'</div>';
        });
    }
}
