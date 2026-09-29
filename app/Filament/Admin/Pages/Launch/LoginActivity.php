<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Launch;

use BackedEnum;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * OPS-016 Login activity — GET security-centre/login-activity (security.centre.read). Same query as
 * SecurityCentreController::loginActivity: sign-ins of the tenant's members, newest first; IP and user agent stay hashed.
 */
final class LoginActivity extends LaunchScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-log-in';

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'security/login-activity';

    protected static array $permissions = ['security.centre.read'];

    protected static string $screen = 'login_activity';

    protected static string $group = 'Trust & compliance';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if ($this->tenantId === null) {
                    return [];
                }
                $members = DB::table('tenant_memberships')->where('tenant_id', $this->tenantId)->pluck('user_id');
                $rows = DB::table('login_activities as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->whereIn('a.user_id', $members)
                    ->orderByDesc('a.occurred_at')->limit(200)
                    ->get(['a.id', 'a.occurred_at', 'u.email', 'a.method', 'a.device_name', 'a.platform', 'a.country_code', 'a.new_device', 'a.anomaly_flags']);

                return self::keyed($rows->map(fn ($a) => [...(array) $a, 'new_device' => (bool) $a->new_device,
                    'anomaly_flags' => implode(', ', (array) json_decode((string) $a->anomaly_flags, true)) ?: '—']));
            })
            ->columns([
                TextColumn::make('occurred_at')->label(self::col('occurred_at'))->dateTime(),
                TextColumn::make('email')->label(self::col('user')),
                TextColumn::make('method')->label(self::col('method'))->badge(),
                TextColumn::make('device_name')->label(self::col('device'))->placeholder('—'),
                TextColumn::make('platform')->label(self::col('platform'))->placeholder('—'),
                TextColumn::make('country_code')->label(self::col('country'))->placeholder('—'),
                IconColumn::make('new_device')->label(self::col('new_device'))->boolean(),
                TextColumn::make('anomaly_flags')->label(self::col('anomaly_flags'))->wrap(),
            ])
            ->emptyStateHeading(__('launch_screens.empty'));
    }
}
