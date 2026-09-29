<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RegulatoryCrm;

use App\Application\PartnerWorkspace\LeadDirectoryService;
use App\Filament\Shared\Actions\CrmLeadActions;
use BackedEnum;
use Filament\Tables\Table;

/** REQ-CRM-001 lead directory — GET crm/leads via LeadDirectoryService::list (crm.leads.read, caller's scope); portfolio transfer. */
final class CrmLeads extends RegulatoryCrmPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-contact';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'crm/leads';

    protected static array $permissions = ['crm.leads.read'];

    protected static string $screen = 'leads';

    protected static string $group = 'Sales workspace';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->ready() ? self::keyed(app(LeadDirectoryService::class)->list(auth()->user(), $this->tenantId)) : [])
            ->columns([
                self::col('full_name'), self::col('phone_e164'), self::col('city'), self::col('product_interest'), self::col('source'),
                self::col('status')->badge(), self::col('created_at')->dateTime(),
            ])
            ->headerActions([CrmLeadActions::leadCreate(), CrmLeadActions::portfolioTransfer()])
            ->recordActions([CrmLeadActions::leadActivity(), CrmLeadActions::leadAssign(), CrmLeadActions::leadTransition()])
            ->emptyStateHeading(__('regulatory_crm_actions.empty'));
    }
}
