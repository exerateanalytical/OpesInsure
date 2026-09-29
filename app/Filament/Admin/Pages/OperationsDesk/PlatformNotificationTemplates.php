<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\OperationsDesk;

use App\Filament\Shared\Actions\OperationsConsoleActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Event notification templates — GET operations/notification-templates (operations.taxonomy.read): platform (tenant NULL)
 * and own-tenant event templates, same query as OperationsTaxonomyController::notificationTemplates. Platform DRAFT
 * templates are approved here (templateApprove, author cannot approve).
 */
final class PlatformNotificationTemplates extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-mail-check';

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'operations/notification-templates';

    protected static array $permissions = ['operations.taxonomy.read'];

    protected static string $screen = 'notification_templates';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->tenantId === null ? [] : self::keyed(DB::table('notification_templates')->whereNotNull('event_code')
                ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $this->tenantId))
                ->orderBy('event_code')->orderBy('channel')->orderBy('locale')->limit(500)
                ->get(['id', 'tenant_id', 'event_code', 'code', 'channel', 'locale', 'version', 'status', 'approved_at'])))
            ->columns([
                TextColumn::make('event_code')->label(self::col('event_code')),
                TextColumn::make('channel')->label(self::col('channel'))->badge(),
                TextColumn::make('locale')->label(self::col('locale')),
                TextColumn::make('version')->label(self::col('version')),
                TextColumn::make('tenant_id')->label(self::col('scope'))->formatStateUsing(fn ($state) => $state ? __('operations_actions.scope_tenant') : __('operations_actions.scope_platform'))
                    ->placeholder(__('operations_actions.scope_platform')),
                TextColumn::make('status')->label(self::col('status'))->badge(),
                TextColumn::make('approved_at')->label(self::col('approved_at'))->dateTime(),
            ])
            ->recordActions([OperationsConsoleActions::templateApprove()])
            ->emptyStateHeading(__('operations_actions.empty'));
    }
}
