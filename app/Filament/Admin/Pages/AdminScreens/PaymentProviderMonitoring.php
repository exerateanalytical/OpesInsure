<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Filament\Shared\Columns;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * OPS-011 Payment provider monitoring — per payment provider of this organisation: payment requests over 24 hours and
 * 7 days by outcome (payment_intents), stuck requests (still waiting after 30 minutes), last success and the
 * configured connection status (payment_provider_connections). Opened with finance.accounts.view (GET
 * finance/reference/payment-providers) or operations.console.view.
 */
final class PaymentProviderMonitoring extends AdminScreenPage
{
    public const WAITING = ['CREATED', 'PENDING_CUSTOMER', 'PROCESSING'];

    protected static string|BackedEnum|null $navigationIcon = 'lucide-credit-card';

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'integrations/payment-provider-monitoring';

    protected static array $permissions = ['finance.accounts.view', 'operations.console.view'];

    protected static string $screen = 'payment_provider_monitoring';

    protected static string $group = 'Integrations';

    private function intents(int $hours): \Illuminate\Database\Query\Builder
    {
        return DB::table('payment_intents')->where('tenant_id', $this->tenantId ?? '00000000-0000-0000-0000-000000000000')->where('created_at', '>=', now()->subHours($hours));
    }

    public function kpis(): array
    {
        $day = (clone $this->intents(24))->count();
        $ok = (clone $this->intents(24))->where('status', 'SUCCEEDED')->count();
        $failed = (clone $this->intents(24))->whereIn('status', ['FAILED', 'EXPIRED'])->count();
        $stuck = (clone $this->intents(24 * 7))->whereIn('status', self::WAITING)->where('created_at', '<', now()->subMinutes(30))->count();

        return [
            self::kpi('payments_24h', $day),
            self::kpi('success_rate_24h', $day > 0 ? round($ok * 100 / $day, 1).' %' : '—', $day > 0 && $ok / $day < 0.8 ? 'warning' : 'success'),
            self::kpi('failed_24h', $failed, $failed > 0 ? 'danger' : 'success'),
            self::kpi('stuck', $stuck, $stuck > 0 ? 'warning' : 'success', __('admin_screens.kpis.stuck_hint')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if ($this->tenantId === null) {
                    return [];
                }
                $week = (clone $this->intents(24 * 7))->selectRaw("provider, count(*) as calls_7d,
                        sum(case when status = 'SUCCEEDED' then 1 else 0 end) as ok_7d,
                        sum(case when status in ('FAILED','EXPIRED') then 1 else 0 end) as failed_7d,
                        sum(case when created_at >= ? then 1 else 0 end) as calls_24h,
                        sum(case when created_at >= ? and status in ('FAILED','EXPIRED') then 1 else 0 end) as failed_24h,
                        sum(case when status in ('CREATED','PENDING_CUSTOMER','PROCESSING') and created_at < ? then 1 else 0 end) as stuck,
                        max(case when status = 'SUCCEEDED' then updated_at end) as last_ok", [now()->subDay(), now()->subDay(), now()->subMinutes(30)])
                    ->groupBy('provider')->get()->keyBy('provider');
                $conns = DB::table('payment_provider_connections')->where('tenant_id', $this->tenantId)->get(['provider', 'environment', 'status'])->groupBy('provider');
                $rows = [];
                foreach ($week->keys()->merge($conns->keys())->unique()->sort() as $provider) {
                    $s = $week->get($provider);
                    $c = $conns->get($provider)?->first();
                    $rows[(string) $provider] = [
                        '__key' => (string) $provider, 'id' => (string) $provider, 'provider' => (string) $provider,
                        'connection' => $c ? $c->status : 'NOT_CONFIGURED', 'environment' => $c->environment ?? '—',
                        'calls_24h' => (int) ($s->calls_24h ?? 0), 'failed_24h' => (int) ($s->failed_24h ?? 0), 'calls_7d' => (int) ($s->calls_7d ?? 0),
                        'rate_7d' => ($s->calls_7d ?? 0) > 0 ? round($s->ok_7d * 100 / $s->calls_7d, 1).' %' : '—',
                        'stuck' => (int) ($s->stuck ?? 0), 'last_ok' => $s->last_ok ?? null,
                    ];
                }

                return $rows;
            })
            ->columns([
                TextColumn::make('provider')->label(self::col('provider'))->description(fn (array $record) => $record['environment']),
                Columns::status('connection', self::col('connection')),
                TextColumn::make('calls_24h')->label(self::col('payments_24h'))->numeric(),
                TextColumn::make('failed_24h')->label(self::col('failures_24h'))->badge()->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'success'),
                TextColumn::make('calls_7d')->label(self::col('payments_7d'))->numeric(),
                TextColumn::make('rate_7d')->label(self::col('success_rate_7d')),
                TextColumn::make('stuck')->label(self::col('stuck'))->badge()->color(fn ($state): string => (int) $state > 0 ? 'warning' : 'success'),
                TextColumn::make('last_ok')->label(self::col('last_success'))->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
