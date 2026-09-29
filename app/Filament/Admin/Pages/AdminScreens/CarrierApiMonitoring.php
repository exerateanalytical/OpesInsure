<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Identity\Rbac\PlatformAuthority;
use App\Application\Integrations\Activa\ActivaCircuitBreaker;
use App\Filament\Shared\Columns;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * OPS-010 Carrier API monitoring — per carrier connection and service, from the call log every carrier gateway
 * writes (carrier_api_calls, e.g. the Activa connector): calls and failures over 24 hours, p95 latency, last success,
 * circuit state. Opened with integrations.manage (as the connector screen) or operations.console.view. The platform
 * tenant sees every connection; another organisation only its own.
 */
final class CarrierApiMonitoring extends AdminScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-radio-tower';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'integrations/carrier-api-monitoring';

    protected static array $permissions = ['integrations.manage', 'operations.console.view'];

    protected static string $screen = 'carrier_api_monitoring';

    protected static string $group = 'Integrations';

    private function connections(): \Illuminate\Database\Query\Builder
    {
        $platform = (bool) rescue(fn () => app(PlatformAuthority::class)->isPlatformTenant($this->tenantId), false, false);

        return DB::table('carrier_api_connections')->when(! $platform, fn ($q) => $q->where('tenant_id', $this->tenantId ?? '00000000-0000-0000-0000-000000000000'));
    }

    private function calls(): \Illuminate\Database\Query\Builder
    {
        return DB::table('carrier_api_calls')->whereIn('connection_id', (clone $this->connections())->select('id'))->where('created_at', '>=', now()->subDay());
    }

    public function kpis(): array
    {
        $total = (clone $this->calls())->count();
        $failed = (clone $this->calls())->where('outcome', '<>', 'OK')->count();
        $lastFail = (clone $this->calls())->where('outcome', '<>', 'OK')->max('created_at');

        return [
            self::kpi('connections', (clone $this->connections())->count(), null, __('admin_screens.kpis.active_n', ['n' => (clone $this->connections())->where('status', 'ACTIVE')->count()])),
            self::kpi('calls_24h', $total),
            self::kpi('failure_rate_24h', $total > 0 ? round($failed * 100 / $total, 1).' %' : '—', $failed > 0 ? 'danger' : 'success'),
            self::kpi('last_failure', $lastFail ? \Illuminate\Support\Carbon::parse($lastFail)->format('d/m/Y H:i') : '—'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $conns = (clone $this->connections())->leftJoin('carriers', 'carriers.id', '=', 'carrier_api_connections.carrier_id')
                    ->get(['carrier_api_connections.id', 'carrier_api_connections.provider', 'carrier_api_connections.environment', 'carrier_api_connections.status',
                        'carrier_api_connections.last_success_at', 'carrier_api_connections.last_error_code', 'carriers.short_name', 'carriers.legal_name'])->keyBy('id');
                if ($conns->isEmpty()) {
                    return [];
                }
                $pgsql = DB::getDriverName() === 'pgsql';
                $stats = DB::table('carrier_api_calls')->whereIn('connection_id', $conns->keys())->where('created_at', '>=', now()->subDay())
                    ->selectRaw('connection_id, service, count(*) as calls, sum(case when outcome <> \'OK\' then 1 else 0 end) as failures, max(case when outcome = \'OK\' then created_at end) as last_ok, '
                        .($pgsql ? 'percentile_cont(0.95) within group (order by duration_ms) as p95' : 'max(duration_ms) as p95'))
                    ->groupBy('connection_id', 'service')->get();
                $breaker = app(ActivaCircuitBreaker::class);
                $rows = [];
                foreach ($conns as $id => $c) {
                    $services = $stats->where('connection_id', $id);
                    foreach ($services->isEmpty() ? [null] : $services as $s) {
                        $key = $id.'|'.($s->service ?? '-');
                        $circuit = $s ? rescue(fn () => $breaker->state($id, $s->service), ['open' => false], false) : ['open' => false];
                        $rows[$key] = [
                            '__key' => $key, 'id' => $key,
                            'carrier' => $c->short_name ?: $c->legal_name ?: $c->provider, 'provider' => $c->provider, 'environment' => $c->environment, 'status' => $c->status,
                            'service' => $s->service ?? '—', 'calls' => (int) ($s->calls ?? 0), 'failures' => (int) ($s->failures ?? 0),
                            'p95' => $s && $s->p95 !== null ? (int) round((float) $s->p95).' ms' : '—',
                            'last_ok' => $s->last_ok ?? $c->last_success_at, 'circuit' => $circuit['open'] ? 'OPEN' : 'CLOSED',
                            'last_error' => $c->last_error_code ?? '—',
                        ];
                    }
                }

                return $rows;
            })
            ->columns([
                TextColumn::make('carrier')->label(self::col('carrier'))->description(fn (array $record) => $record['provider'].' · '.$record['environment']),
                Columns::status('status', self::col('status')),
                TextColumn::make('service')->label(self::col('service')),
                TextColumn::make('calls')->label(self::col('calls_24h'))->numeric(),
                TextColumn::make('failures')->label(self::col('failures_24h'))->badge()->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'success'),
                TextColumn::make('p95')->label(self::col('p95_latency')),
                TextColumn::make('circuit')->label(self::col('circuit'))->badge()->color(fn ($state): string => $state === 'OPEN' ? 'danger' : 'success'),
                TextColumn::make('last_ok')->label(self::col('last_success'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('last_error')->label(self::col('last_error')),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
