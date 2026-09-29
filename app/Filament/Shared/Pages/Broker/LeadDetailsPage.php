<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Application\PartnerWorkspace\LeadDirectoryService;
use App\Filament\Shared\Actions\CrmLeadActions;
use App\Models\{Partner, User};
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * BRK-010 Lead Details: GET crm/leads/{lead} + /activities (crm.leads.read) through LeadDirectoryService::find, which
 * 404s for a lead outside the caller's scope (own firm). Assignment history = LeadDirectoryService::assignments.
 * Header actions are the same CrmLeadActions as the directory, bound to this lead.
 */
final class LeadDetailsPage extends BrokerScreen
{
    protected static ?string $slug = 'leads/details';

    protected static bool $shouldRegisterNavigation = false;

    protected static array $permissions = ['crm.leads.read'];

    protected static string $screen = 'lead_details';

    #[Url, Locked]
    public ?string $lead = null;

    public function mount(): void
    {
        parent::mount();
        $this->row(); // 404 when not in the caller's scope
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $this->tenantId && $this->lead, 404);

        $row = (array) app(LeadDirectoryService::class)->find($this->lead, $user, $this->tenantId);

        return $row + ['__key' => (string) $row['id']];
    }

    public function getTitle(): string
    {
        return (string) ($this->row()['full_name'] ?? parent::getTitle());
    }

    public function getDetailSections(): array
    {
        $l = $this->row();
        $leads = app(LeadDirectoryService::class);
        $f = fn (string $k) => __('broker_screens_a.columns.'.$k);
        $rows = [
            $f('status') => self::code('lead_status', $l['status']), $f('phone') => $l['phone_e164'], $f('city') => $l['city'],
            $f('product_interest') => $l['product_interest'], $f('source') => $l['source'],
            $f('partner') => $l['partner_id'] ? (Partner::with('party')->find($l['partner_id'])?->party?->display_name) : null,
            $f('assigned_to') => $l['assigned_user_id'] ? User::find($l['assigned_user_id'])?->full_name : null,
            $f('lost_reason') => $l['lost_reason'], $f('notes') => $l['notes'],
            $f('created_at') => Carbon::parse($l['created_at'])->format('d/m/Y H:i'),
        ];
        $history = [];
        foreach ($leads->assignments((object) $l) as $a) {
            $history[Carbon::parse($a->occurred_at)->format('d/m/Y H:i').' · '.$a->rule] = trim((User::find($a->to_user_id)?->full_name ?? '—').($a->reason ? ' — '.$a->reason : ''));
        }

        return array_values(array_filter([
            ['heading' => __('broker_screens_a.lead_details.summary'), 'rows' => $rows],
            $history !== [] ? ['heading' => __('broker_screens_a.lead_details.assignments'), 'rows' => $history] : null,
        ]));
    }

    protected function getHeaderActions(): array
    {
        return [
            CrmLeadActions::leadActivity()->record(fn () => $this->row()),
            CrmLeadActions::leadTransition()->record(fn () => $this->row()),
            CrmLeadActions::leadAssign()->record(fn () => $this->row()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('broker_screens_a.lead_details.activities'))
            ->records(fn (): array => self::keyed(app(LeadDirectoryService::class)->activities((object) $this->row())->map(fn ($e) => [
                'id' => $e->id, 'entry_type' => $e->entry_type, 'body' => $e->body, 'follow_up_at' => $e->follow_up_at, 'created_at' => $e->created_at,
                'author' => User::find($e->author_id)?->full_name,
            ])))
            ->columns([
                self::col('created_at')->dateTime(), self::col('entry_type', 'activity_type')->badge()->formatStateUsing(fn (?string $state) => self::code('activity', $state)),
                self::col('body', 'note')->wrap(), self::col('author'), self::col('follow_up_at', 'follow_up')->dateTime(),
            ])
            ->emptyStateHeading(__('broker_screens_a.empty'));
    }
}
