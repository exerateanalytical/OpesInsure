<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use App\Application\Providers\Workspace\ProviderWorkspaceRegister;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Provider Portal screen "disputes" (Gap-Free spec ui_screen_register): open a dispute on a claim, claim line, settlement or
 * reconciliation through the same controller action as POST /api/v1/provider-portal/disputes. Resolution is insurer-side.
 */
final class DisputesPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'disputes';

    protected static string $permission = 'provider.dispute.view';

    protected static string $screen = 'disputes';

    public const SUBJECTS = ['CLAIM', 'CLAIM_LINE', 'SETTLEMENT', 'RECONCILIATION'];

    public string $subject_type = 'CLAIM';

    public ?string $claim_id = null;

    public ?string $claim_line_no = null;

    public ?string $settlement_batch_id = null;

    public ?string $reconciliation_id = null;

    public string $reason_code = 'TARIFF_DIFFERENCE';

    public ?string $disputed_amount_minor = null;

    public ?string $description = null;

    public function mount(): void
    {
        foreach (['claim_id', 'settlement_batch_id', 'reconciliation_id'] as $k) {
            $this->{$k} = request()->query($k) ?: $this->{$k};
        }
        if (in_array(request()->query('subject_type'), self::SUBJECTS, true)) {
            $this->subject_type = request()->query('subject_type');
        }
    }

    public function extraView(): ?string
    {
        return 'provider-workspace.dispute-form';
    }

    /** @return array<string, string> */
    public function subjectOptions(): array
    {
        return array_combine(self::SUBJECTS, self::SUBJECTS);
    }

    /** @return array<string, string> */
    public function reasonOptions(): array
    {
        return array_combine(ProviderWorkspaceRegister::DISPUTE_REASONS, ProviderWorkspaceRegister::DISPUTE_REASONS);
    }

    public function openDispute(): void
    {
        $subject = match ($this->subject_type) {
            'CLAIM' => ['claim_id' => $this->claim_id],
            'CLAIM_LINE' => ['claim_id' => $this->claim_id, 'claim_line_no' => $this->claim_line_no],
            'SETTLEMENT' => ['settlement_batch_id' => $this->settlement_batch_id],
            default => ['reconciliation_id' => $this->reconciliation_id],
        };
        $res = $this->callWorkspace('disputeStore', array_filter(['subject_type' => $this->subject_type, 'reason_code' => $this->reason_code,
            'disputed_amount_minor' => $this->disputed_amount_minor, 'description' => $this->description] + $subject, fn ($v) => $v !== null && $v !== ''));
        if ($res !== null) {
            $this->selected = $res['id'] ?? null;
            $this->description = $this->disputed_amount_minor = null;
        }
    }

    public function rowActions(array $row): array
    {
        return isset($row['id']) ? [['label' => __('provider_workspace.ui.open'), 'action' => 'open', 'arg' => $row['id']]] : [];
    }

    protected function detail(): ?array
    {
        $d = (array) $this->ops()->dispute($this->tenantId(), $this->user(), $this->scope(), $this->selected);

        return ['title' => __('provider_workspace.screens.disputes').' '.($d['dispute_number'] ?? ''),
            'cards' => array_intersect_key($d, array_flip(['dispute_number', 'subject_type', 'reason_code', 'status', 'disputed_amount_minor', 'resolution_amount_minor', 'currency', 'description', 'response', 'resolved_at']))];
    }

    protected function columns(): array
    {
        return ['dispute_number', 'subject_type', 'reason_code', 'status', 'disputed_amount_minor', 'currency', 'created_at'];
    }

    protected function rows(): array
    {
        return $this->ops()->disputes($this->tenantId(), $this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
