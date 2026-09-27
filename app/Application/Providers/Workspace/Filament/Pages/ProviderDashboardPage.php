<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;

/** Provider Portal screen "provider_dashboard" (Gap-Free spec ui_screen_register). */
final class ProviderDashboardPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-house';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'dashboard';

    protected static string $permission = 'provider.dashboard.view';

    protected static string $screen = 'provider_dashboard';

    /** @var array<string, mixed>|null */
    private ?array $board = null;

    /** @return array<string, mixed> */
    private function board(): array
    {
        return $this->board ??= $this->ws()->dashboard($this->tenantId(), $this->user(), $this->scope());
    }

    /**
     * Spec dashboards per desk (UI audit 2026-09-27): insurance desk (eligibility / preauth / admissions) for roles that check
     * eligibility or raise preauthorizations, claims & billing for claim handlers, executive + finance (amounts owed per insurer) only for
     * holders of provider.finance.view — reception never sees receivables.
     */
    protected function cards(): array
    {
        $d = $this->board();
        $cards = [];
        if ($this->allows('provider.finance.view')) {
            $cards += $d['provider_executive'] + ['unreconciled_payments' => $d['finance']['unreconciled_payments'] ?? 0];
        }
        if ($this->allows('provider.eligibility.check') || $this->allows('provider.preauth.create')) {
            $cards += $d['insurance_desk'];
        }
        if ($this->allows('provider.claim.view')) {
            $cards += $d['claims_billing'];
        }

        return array_filter($cards, fn ($v) => ! is_array($v));
    }

    protected function columns(): array
    {
        return ['insurer_name', 'currency', 'submitted_amount', 'approved_amount', 'pending_amount', 'rejected_amount', 'payable_amount', 'paid_amount', 'outstanding_amount',
            'disputed_amount', 'patient_share_amount', 'average_settlement_days'];
    }

    protected function rows(): array
    {
        return $this->allows('provider.finance.view') ? $this->board()['finance']['by_insurer'] : [];
    }
}
