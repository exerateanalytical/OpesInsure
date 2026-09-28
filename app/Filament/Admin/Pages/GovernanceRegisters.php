<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\Compliance\Governance\GovernanceRegisterService;
use App\Application\WebExperiences\PortalAuthorization;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\ComplianceActions;
use App\Filament\Shared\Columns;
use App\Filament\Shared\Concerns\ListScreen;
use App\Models\RegisterRow;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * REQ-CMP-003 ICT and vendor / outsourcing governance registers (tenant-scoped). One screen, one register at a time
 * (switcher in the header); create / edit / exit-plan approval go through GovernanceRegisterService, like the API.
 */
final class GovernanceRegisters extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-landmark';

    protected static ?string $slug = 'governance-registers';

    protected static ?int $navigationSort = 90;

    #[Url]
    public string $register = 'vendors';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && rescue(fn () => app(TenantContext::class)->id(), null, false) !== null
            && PortalAuthorization::allowsRead($u, 'compliance.governance.read');
    }

    public static function getNavigationLabel(): string
    {
        return __('compliance_actions.governance.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Trust & compliance';
    }

    public function getTitle(): string
    {
        return __('compliance_actions.governance.title').' — '.__('compliance_actions.governance.registers.'.$this->register);
    }

    public function mount(): void
    {
        $this->tenantId = rescue(fn () => app(TenantContext::class)->id(), null, false);
        if (! array_key_exists($this->register, GovernanceRegisterService::REGISTERS)) {
            $this->register = 'vendors';
        }
    }

    public function hydrate(): void
    {
        if ($this->tenantId !== null) {
            app(TenantContext::class)->set($this->tenantId);
        }
    }

    protected function getHeaderActions(): array
    {
        $switch = [];
        foreach (array_keys(GovernanceRegisterService::REGISTERS) as $slug) {
            $switch[] = Action::make('show_'.str_replace('-', '_', $slug))->label(__('compliance_actions.governance.registers.'.$slug))
                ->action(function () use ($slug) {
                    $this->register = $slug;
                    $this->resetTable();
                });
        }

        return [
            ActionGroup::make($switch)->label(__('compliance_actions.governance.switch'))->icon('lucide-list')->button()->color('gray'),
            ComplianceActions::governanceCreate(fn () => $this->register),
        ];
    }

    public function table(Table $table): Table
    {
        $register = $this->register;
        $name = GovernanceRegisterService::REGISTERS[$register] ?? 'governance_vendors';
        $columns = [];
        foreach (array_slice(ComplianceActions::GOVERNANCE_FIELDS[$register] ?? [], 0, 4, true) as $field => [$kind]) {
            if (str_starts_with($kind, 'ref:') || $kind === 'member' || $kind === 'textarea' && $field !== 'summary') {
                continue;
            }
            $label = __('compliance_actions.fields.'.$field);
            $columns[] = match (true) {
                $kind === 'date' => Columns::date($field, false, $label),
                $kind === 'datetime' => Columns::date($field, true, $label),
                $kind === 'rating' || str_starts_with($kind, 'in:') => Columns::status($field, $label),
                default => Columns::text($field, $label)->searchable()->limit(60),
            };
        }
        if (in_array($register, ['ict-incidents'], true)) {
            array_unshift($columns, Columns::text('incident_number', __('compliance_actions.fields.incident_number'))->searchable());
        }
        if (in_array($register, ['ict-assets', 'ict-incidents', 'vendors', 'outsourcing-contracts', 'exit-plans'], true)) {
            $columns[] = Columns::status('status', __('compliance_actions.fields.status'));
        }
        $columns[] = Columns::text('version', __('compliance_actions.fields.version'));
        $columns[] = Columns::date('created_at', true, __('compliance_actions.fields.created_at'));

        return ListScreen::apply($table
            ->query(fn () => RegisterRow::on_($name)->where('tenant_id', (string) ($this->tenantId ?? app(TenantContext::class)->id())))
            ->defaultSort('created_at', 'desc')
            ->columns($columns)
            ->recordActions([ComplianceActions::governanceUpdate(fn () => $this->register), ComplianceActions::exitPlanApprove(fn () => $this->register)]), $name);
    }
}
