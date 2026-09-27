<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use App\Application\Health\Preauth\PreauthLifecycle;
use App\Application\Providers\Portal\ProviderPortalService;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Provider Portal screens "preauthorization_list", "new_preauthorization", "preauthorization_detail", "preauthorization_query_response" (Gap-Free spec
 * ui_screen_register). The request form renders the type-specific fields of PreauthLifecycle::TYPE_FIELDS and posts
 * through the same controller action as POST /api/v1/provider-portal/preauthorizations. The insurer decision is taken
 * insurer-side; the provider answers information requests or cancels its own request.
 */
final class PreauthorizationsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'preauthorizations';

    protected static string $permission = 'provider.preauth.view';

    protected static string $screen = 'preauthorization_list';

    public string $request_type = 'OUTPATIENT';

    public ?string $policy_id = null;

    public ?string $member_ref = null;

    public ?string $facility_id = null;

    public ?string $service_code = null;

    public string $quantity = '1';

    public ?string $unit_price_minor = null;

    public ?string $clinical_notes = null;

    /** @var array<string, mixed> type-specific fields (details.*) */
    public array $details = [];

    public ?string $answer = null;

    public ?string $cancel_reason = null;

    public function mount(): void
    {
        foreach (['policy_id', 'member_ref', 'service_code'] as $k) {
            $this->{$k} = request()->query($k) ?: $this->{$k};
        }
        if (in_array(request()->query('request_type'), PreauthLifecycle::TYPES, true)) {
            $this->request_type = request()->query('request_type');
        }
    }

    public function extraView(): ?string
    {
        return 'provider-workspace.preauth-form';
    }

    public function canCreate(): bool
    {
        return (bool) rescue(fn () => $this->user()->hasPermission('provider.preauth.create'), false, false);
    }

    /** @return array<string, array{0: bool, 1: string}> */
    public function typeFields(): array
    {
        return PreauthLifecycle::TYPE_FIELDS[$this->request_type] ?? [];
    }

    /** @return array<string, string> */
    public function facilityOptions(): array
    {
        return collect(rescue(fn () => app(ProviderPortalService::class)->facilities($this->scope()), [], false))
            ->mapWithKeys(fn ($f) => [((array) $f)['id'] => ((array) $f)['code'].' — '.((array) $f)['name']])->all();
    }

    public function submitRequest(): void
    {
        $line = array_filter(['service_code' => $this->service_code, 'quantity' => $this->quantity, 'unit_price_minor' => $this->unit_price_minor === null || $this->unit_price_minor === '' ? null : (int) $this->unit_price_minor], fn ($v) => $v !== null && $v !== '');
        $details = array_filter($this->details, fn ($v) => $v !== null && $v !== '');
        $res = $this->callWorkspace('preauthStore', array_filter(['request_type' => $this->request_type, 'policy_id' => $this->policy_id, 'member_ref' => $this->member_ref,
            'facility_id' => $this->facility_id ?: null, 'clinical_notes' => $this->clinical_notes, 'details' => $details, 'lines' => [$line]], fn ($v) => $v !== null && $v !== ''),
            null, ['lines.0.service_code' => 'service_code', 'lines.0.quantity' => 'quantity', 'lines.0.unit_price_minor' => 'unit_price_minor']);
        if ($res !== null) {
            $this->selected = $res['id'] ?? null;
            $this->details = [];
            $this->clinical_notes = null;
        }
    }

    public function respond(): void
    {
        if ($this->selected && $this->callWorkspace('preauthRespond', ['answer' => (string) $this->answer], $this->selected) !== null) {
            $this->answer = null;
        }
    }

    public function cancelRequest(): void
    {
        if ($this->selected && $this->callWorkspace('preauthCancel', ['reason' => (string) $this->cancel_reason], $this->selected, ['reason' => 'cancel_reason']) !== null) {
            $this->cancel_reason = null;
        }
    }

    public function rowActions(array $row): array
    {
        return isset($row['id']) ? [['label' => __('provider_workspace.ui.open'), 'action' => 'open', 'arg' => $row['id']]] : [];
    }

    protected function detail(): ?array
    {
        $d = $this->ws()->preauthorization($this->tenantId(), $this->user(), $this->scope(), $this->selected);
        $cards = array_intersect_key($d, array_flip(['preauth_number', 'request_type', 'status', 'decision', 'decision_reason_code', 'info_request', 'requested_amount_minor',
            'approved_amount_minor', 'currency', 'gop_valid_from', 'gop_valid_until', 'admitted_on', 'approved_until', 'discharged_on']));

        return ['title' => __('provider_workspace.screens.preauthorization_detail').' '.($d['preauth_number'] ?? ''), 'cards' => $cards,
            'rows' => array_map(fn ($l) => (array) $l, $d['lines'] ?? [])];
    }

    /** Status of the opened request (drives the respond / cancel forms). */
    public function selectedStatus(): ?string
    {
        return $this->selected ? $this->ws()->preauthQuery($this->tenantId(), $this->user(), $this->scope())->where('id', $this->selected)->value('status') : null;
    }

    /** Lifecycle event allowed from $status (PreauthLifecycle) and the user holds the permission. */
    public function may(string $event, ?string $status, string $permission): bool
    {
        return $status !== null && PreauthLifecycle::target($status, $event) !== null && (bool) rescue(fn () => $this->user()->hasPermission($permission), false, false);
    }

    protected function columns(): array
    {
        return ['preauth_number', 'request_type', 'status', 'service_date', 'member_ref', 'requested_amount_minor', 'approved_amount_minor', 'currency', 'approved_until'];
    }

    protected function rows(): array
    {
        return $this->ws()->preauthorizations($this->tenantId(), $this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
