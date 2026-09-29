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
 * BRK-011 Lead Assignment: the caller's open leads (LeadDirectoryService::list, own firm), unassigned first, with the
 * assign action (POST crm/leads/{lead}/assign, crm.leads.assign). Opens only for holders of crm.leads.assign.
 */
final class LeadAssignmentPage extends BrokerScreen
{
    protected static ?string $slug = 'lead-assignment';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-user-cog';

    protected static ?int $navigationSort = 11;

    protected static array $permissions = ['crm.leads.assign'];

    protected static string $screen = 'lead_assignment';

    /** @return list<array<string, mixed>> */
    private function open(): array
    {
        $user = auth()->user();
        if (! $this->tenantId || ! $user instanceof User) {
            return [];
        }

        return app(LeadDirectoryService::class)->list($user, $this->tenantId)->filter(fn ($l) => LeadPipeline::isOpen($l->status))
            ->sortBy(fn ($l) => [$l->assigned_user_id === null ? 0 : 1, $l->created_at])
            ->map(fn ($l) => (array) $l + ['assignee' => $l->assigned_user_id ? User::find($l->assigned_user_id)?->full_name : null])->values()->all();
    }

    public function getStats(): array
    {
        $open = collect($this->open());

        return [
            ['key' => 'leads_open', 'label' => __('broker_screens_a.metrics.leads_open'), 'value' => (string) $open->count(), 'tone' => 'info'],
            ['key' => 'leads_unassigned', 'label' => __('broker_screens_a.metrics.leads_unassigned'), 'value' => (string) $open->whereNull('assigned_user_id')->count(), 'tone' => 'warning'],
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => self::keyed($this->open()))
            ->columns([
                self::col('full_name', 'lead'), self::col('phone_e164', 'phone'), self::col('product_interest'),
                self::col('status')->badge()->formatStateUsing(fn (?string $state) => self::code('lead_status', $state)),
                self::col('assignee', 'assigned_to'), self::col('created_at')->since(),
            ])
            ->recordUrl(fn (array $record) => LeadDetailsPage::getUrl(['lead' => $record['id']]))
            ->recordActions([CrmLeadActions::leadAssign()])
            ->emptyStateHeading(__('broker_screens_a.empty'));
    }
}
