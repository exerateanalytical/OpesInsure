<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Launch;

use App\Application\Compliance\Aml\Str\TippingOffGuard;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * CMP-020 Compliance audit trail / ADM-033 System audit logs — GET compliance/audit-log (audit.read). Tenant-scoped here
 * (the staff screen shows the signed-in tenant's trail only); STR traces stay hidden without cases.str.view (tipping-off).
 */
final class AuditTrail extends LaunchScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-scroll-text';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'compliance/audit-trail';

    protected static array $permissions = ['audit.read'];

    protected static string $screen = 'audit_trail';

    protected static string $group = 'Trust & compliance';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if ($this->tenantId === null) {
                    return [];
                }
                $q = DB::table('audit_log')->where('tenant_id', $this->tenantId)->orderByDesc('sequence')->limit(200);
                if (! auth()->user()?->hasPermission('cases.str.view')) {
                    TippingOffGuard::hideFromAudit($q);
                }

                return self::keyed($q->get(['id', 'sequence', 'created_at', 'action', 'subject_type', 'subject_id', 'actor_id', 'correlation_id']));
            })
            ->columns([
                TextColumn::make('sequence')->label(self::col('sequence')),
                TextColumn::make('created_at')->label(self::col('occurred_at'))->dateTime(),
                TextColumn::make('action')->label(self::col('action'))->badge(),
                TextColumn::make('subject_type')->label(self::col('subject_type')),
                TextColumn::make('subject_id')->label(self::col('subject_id'))->limit(12)->tooltip(fn ($state) => $state),
                TextColumn::make('actor_id')->label(self::col('actor_id'))->limit(12)->tooltip(fn ($state) => $state),
                TextColumn::make('correlation_id')->label(self::col('correlation_id'))->limit(16)->tooltip(fn ($state) => $state),
            ])
            ->emptyStateHeading(__('launch_screens.empty'));
    }
}
