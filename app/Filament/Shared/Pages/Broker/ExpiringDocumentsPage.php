<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Filament\Shared\Actions\KycActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Tables\Table;
use Livewire\Attributes\Url;

/**
 * BRK-023 Expiring Documents: approved KYC whose evidence validity ends within the window (GET kyc/expiring?days=,
 * same query as KycController::expiring: APPROVED, expires_at <= now + days, not superseded), caller's book only.
 * Window 30 / 60 / 90 days (default 60). Action: start the renewal cycle (remediate, kyc.manage).
 */
final class ExpiringDocumentsPage extends KycScreen
{
    protected static ?string $slug = 'kyc/expiring';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-calendar-clock';

    protected static ?int $navigationSort = 23;

    protected static string $screen = 'kyc_expiring';

    #[Url]
    public int $days = 60;

    public function getSubheading(): ?string
    {
        return __('broker_screens_a.kyc_expiring.window', ['days' => $this->window()]);
    }

    private function window(): int
    {
        return in_array($this->days, [30, 60, 90], true) ? $this->days : 60;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('window')->label(__('broker_screens_a.kyc_expiring.change'))->icon('lucide-calendar-range')
            ->schema([Select::make('days')->label(__('broker_screens_a.kyc_expiring.days'))->options([30 => '30', 60 => '60', 90 => '90'])->default($this->window())->required()])
            ->action(fn (array $data) => $this->days = (int) $data['days'])];
    }

    public function table(Table $table): Table
    {
        return $this->submissionTable($table, self::submissions($this->tenantId)->where('status', 'APPROVED')->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($this->window()))->whereNull('superseded_by_submission_id')->orderBy('expires_at'), [KycActions::remediate()]);
    }
}
