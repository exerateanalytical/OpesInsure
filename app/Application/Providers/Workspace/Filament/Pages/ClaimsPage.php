<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use App\Application\Providers\Portal\ProviderPortalService;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Provider Portal screens "claims_list", "new_provider_claim", "claim_detail" (with the per-line explanation of benefits)
 * and "claim_query_response" (Gap-Free spec). Capture, submit and query answers go through the same controller actions as
 * POST /api/v1/provider-portal/claims, /claims/{id}/submit and /claims/{id}/respond-to-query. Adjudication is insurer-side.
 */
final class ClaimsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'claims';

    protected static string $permission = 'provider.claim.view';

    protected static string $screen = 'claims_list';

    public ?string $contract_id = null;

    public ?string $invoice_reference = null;

    public ?string $service_date = null;

    public ?string $policy_id = null;

    public ?string $member_reference = null;

    public ?string $preauth_id = null;

    public ?string $facility_id = null;

    /** @var list<array{medical_service_id: ?string, provider_code: ?string, quantity: string, unit_price_minor: ?string}> */
    public array $lines = [['medical_service_id' => null, 'provider_code' => null, 'quantity' => '1', 'unit_price_minor' => null]];

    public ?string $query_response = null;

    public function extraView(): ?string
    {
        return 'provider-workspace.claim-form';
    }

    public function can(string $permission): bool
    {
        return (bool) rescue(fn () => $this->user()->hasPermission($permission), false, false);
    }

    /** @return array<string, string> */
    public function contractOptions(): array
    {
        return collect(rescue(fn () => app(ProviderPortalService::class)->contracts($this->tenantId(), $this->scope()), [], false))
            ->mapWithKeys(fn ($c) => [$c->id => $c->contract_number.' — '.$c->network_name])->all();
    }

    /** Services priced on the chosen contract's approved tariff (ProviderPortalService::tariffs). @return array<string, string> */
    public function serviceOptions(): array
    {
        if (! $this->contract_id) {
            return [];
        }

        return collect(rescue(fn () => app(ProviderPortalService::class)->tariffs($this->tenantId(), $this->scope(), $this->contract_id), [], false))
            ->where('status', 'APPROVED')->flatMap(fn ($v) => $v->lines)
            ->mapWithKeys(fn ($l) => [$l->medical_service_id => $l->code.' — '.$l->name.' ('.number_format((int) $l->contracted_price_minor).')'])->all();
    }

    public function addLine(): void
    {
        $this->lines[] = ['medical_service_id' => null, 'provider_code' => null, 'quantity' => '1', 'unit_price_minor' => null];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines) ?: [['medical_service_id' => null, 'provider_code' => null, 'quantity' => '1', 'unit_price_minor' => null]];
    }

    public function createClaim(): void
    {
        $lines = array_map(fn ($l) => array_filter(['medical_service_id' => $l['medical_service_id'] ?: null, 'provider_code' => $l['provider_code'] ?: null,
            'quantity' => (int) ($l['quantity'] ?: 1), 'unit_price_minor' => $l['unit_price_minor'] === null || $l['unit_price_minor'] === '' ? null : (int) $l['unit_price_minor']], fn ($v) => $v !== null), $this->lines);
        $res = $this->callWorkspace('claimStore', array_filter(['contract_id' => $this->contract_id, 'invoice_reference' => $this->invoice_reference, 'service_date' => $this->service_date,
            'policy_id' => $this->policy_id, 'member_reference' => $this->member_reference, 'preauth_id' => $this->preauth_id, 'facility_id' => $this->facility_id, 'lines' => $lines],
            fn ($v) => $v !== null && $v !== ''));
        if ($res !== null) {
            $this->selected = $res['id'] ?? null;
            $this->invoice_reference = null;
            $this->lines = [['medical_service_id' => null, 'provider_code' => null, 'quantity' => '1', 'unit_price_minor' => null]];
        }
    }

    public function submitClaim(): void
    {
        if ($this->selected) {
            $this->callWorkspace('claimSubmit', [], $this->selected);
        }
    }

    public function respond(): void
    {
        if ($this->selected && $this->callWorkspace('claimRespond', ['response' => (string) $this->query_response], $this->selected, ['response' => 'query_response']) !== null) {
            $this->query_response = null;
        }
    }

    public function selectedStatus(): ?string
    {
        return $this->selected ? $this->ws()->claimQuery($this->user(), $this->scope())->where('c.id', $this->selected)->value('c.status') : null;
    }

    public function rowActions(array $row): array
    {
        return isset($row['id']) ? [['label' => __('provider_workspace.ui.open'), 'action' => 'open', 'arg' => $row['id']]] : [];
    }

    /** Claim detail = explanation of benefits: per-line billed / allowed / shares / rejected with the deduction reason. */
    protected function detail(): ?array
    {
        $c = $this->ws()->claim($this->user(), $this->scope(), $this->selected);

        return ['title' => __('provider_workspace.screens.claim_detail').' '.($c['claim_number'] ?? ''),
            'cards' => array_intersect_key($c, array_flip(['claim_number', 'invoice_reference', 'status', 'service_date', 'currency', 'billed_minor', 'allowed_minor', 'copay_minor',
                'insurer_share_minor', 'member_share_minor', 'rejected_minor', 'submitted_at', 'adjudicated_at', 'paid_at'])),
            'rows' => array_map(fn ($l) => array_intersect_key($l, array_flip(['line_no', 'service_code', 'service_name', 'quantity', 'billed_minor', 'allowed_minor', 'copay_minor',
                'insurer_share_minor', 'member_share_minor', 'rejected_minor', 'decision', 'deduction_reason_code', 'explanation'])), $c['lines'] ?? [])];
    }

    protected function columns(): array
    {
        return ['claim_number', 'invoice_reference', 'status', 'service_date', 'member_reference', 'billed_minor', 'insurer_share_minor', 'member_share_minor', 'rejected_minor', 'currency'];
    }

    protected function rows(): array
    {
        return $this->ws()->claims($this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
