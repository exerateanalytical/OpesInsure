<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;

/**
 * Provider Portal screen "reconciliation" (Gap-Free spec ui_screen_register): record a payment received from an insurer and
 * allocate it to approved/paid claims, through the same controller actions as POST /api/v1/provider-portal/reconciliations/…
 */
final class ReconciliationsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-arrow-left-right';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'reconciliations';

    protected static string $permission = 'provider.reconciliation.view';

    protected static string $screen = 'reconciliation';

    public ?string $payment_reference = null;

    public ?string $received_on = null;

    public string $currency = 'XAF';

    public ?string $amount_minor = null;

    public ?string $settlement_batch_id = null;

    public ?string $claim_id = null;

    public ?string $allocation_minor = null;

    public ?string $reason = null;

    public function extraView(): ?string
    {
        return 'provider-workspace.reconciliation-form';
    }

    public function recordPayment(): void
    {
        $res = $this->callWorkspace('reconciliationStore', array_filter(['payment_reference' => $this->payment_reference, 'received_on' => $this->received_on,
            'currency' => strtoupper($this->currency), 'amount_minor' => $this->amount_minor, 'settlement_batch_id' => $this->settlement_batch_id], fn ($v) => $v !== null && $v !== ''));
        if ($res !== null) {
            $this->selected = $res['id'] ?? null;
            $this->payment_reference = $this->amount_minor = $this->settlement_batch_id = null;
        }
    }

    public function allocate(): void
    {
        if ($this->selected && $this->callWorkspace('reconciliationMatch', ['allocations' => [['claim_id' => $this->claim_id, 'amount_minor' => $this->allocation_minor]], 'reason' => (string) $this->reason],
            $this->selected, ['allocations.0.claim_id' => 'claim_id', 'allocations.0.amount_minor' => 'allocation_minor']) !== null) {
            $this->claim_id = $this->allocation_minor = $this->reason = null;
        }
    }

    /** Provider claims that can take an allocation (same claim list as the API). @return array<string, string> */
    public function claimOptions(): array
    {
        return collect(rescue(fn () => $this->ws()->claims($this->user(), $this->scope(), ['per_page' => 200])['data'], [], false))
            ->map(fn ($c) => (array) $c)
            ->filter(fn ($c) => in_array($c['status'] ?? null, ['APPROVED', 'PARTIALLY_APPROVED', 'PAYABLE', 'PAID'], true))
            ->mapWithKeys(fn ($c) => [$c['id'] => ($c['claim_number'] ?? '').' — '.($c['invoice_reference'] ?? '')])->all();
    }

    public function unallocated(): int
    {
        return $this->selected ? (int) rescue(fn () => $this->ops()->reconciliation($this->tenantId(), $this->user(), $this->scope(), $this->selected)->unallocated_minor, 0, false) : 0;
    }

    public function rowActions(array $row): array
    {
        return isset($row['id']) ? [['label' => __('provider_workspace.ui.open'), 'action' => 'open', 'arg' => $row['id']]] : [];
    }

    protected function detail(): ?array
    {
        $r = (array) $this->ops()->reconciliation($this->tenantId(), $this->user(), $this->scope(), $this->selected);

        return ['title' => __('provider_workspace.screens.reconciliation').' '.($r['payment_reference'] ?? ''),
            'cards' => array_intersect_key($r, array_flip(['payment_reference', 'received_on', 'currency', 'amount_minor', 'allocated_minor', 'unallocated_minor', 'status'])),
            'rows' => array_map(fn ($l) => array_intersect_key((array) $l, array_flip(['claim_number', 'invoice_reference', 'amount_minor', 'match_type', 'reason', 'created_at'])), $r['lines'] ?? [])];
    }

    protected function columns(): array
    {
        return ['payment_reference', 'received_on', 'currency', 'amount_minor', 'allocated_minor', 'unallocated_minor', 'status'];
    }

    protected function rows(): array
    {
        return $this->ops()->reconciliations($this->tenantId(), $this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
