<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Application\PartnerWorkspace\LeadDirectoryService;
use App\Application\PartnerWorkspace\LeadPipeline;
use App\Filament\Shared\Actions\CrmLeadActions;
use App\Models\User;
use BackedEnum;
use Filament\Tables\Table;

/**
 * BRK-009 Lead Directory: GET crm/leads (crm.leads.read) = LeadDirectoryService::list for the caller (the service
 * narrows a broker user to its own firm's leads), pipeline counts = LeadDirectoryService::pipeline (the API's meta).
 * Writes are CrmLeadActions (POST crm/leads, /activities, /transitions, /assign with their API permissions).
 */
final class LeadDirectoryPage extends BrokerScreen
{
    protected static ?string $slug = 'leads';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-target';

    protected static ?int $navigationSort = 10;

    protected static array $permissions = ['crm.leads.read'];

    protected static string $screen = 'leads';

    public function getStats(): array
    {
        $user = auth()->user();
        if (! $this->tenantId || ! $user instanceof User) {
            return [];
        }
        $counts = app(LeadDirectoryService::class)->pipeline($user, $this->tenantId);

        return array_map(fn (string $s) => ['key' => 'lead_'.strtolower($s), 'label' => (string) self::code('lead_status', $s), 'value' => (string) $counts[$s], 'tone' => 'info'], LeadPipeline::STATUSES);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->tenantId && auth()->user() instanceof User ? self::keyed(app(LeadDirectoryService::class)->list(auth()->user(), $this->tenantId)) : [])
            ->columns([
                self::col('full_name', 'lead'), self::col('phone_e164', 'phone'), self::col('city'), self::col('product_interest'), self::col('source'),
                self::col('status')->badge()->formatStateUsing(fn (?string $state) => self::code('lead_status', $state)),
                self::col('created_at')->dateTime(),
            ])
            ->recordUrl(fn (array $record) => LeadDetailsPage::getUrl(['lead' => $record['id']]))
            ->headerActions([CrmLeadActions::leadCreate()])
            ->recordActions([CrmLeadActions::leadActivity(), CrmLeadActions::leadTransition(), CrmLeadActions::leadAssign()])
            ->emptyStateHeading(__('broker_screens_a.empty'));
    }
}
