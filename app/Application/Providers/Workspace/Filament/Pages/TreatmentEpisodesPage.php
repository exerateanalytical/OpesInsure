<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use App\Application\Providers\Workspace\ProviderOperationsService;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Provider Portal screen "treatment_episodes" (Gap-Free spec ui_screen_register): open an episode, add services, close it
 * and generate the claim — each through the same controller action as POST /api/v1/provider-portal/treatment-episodes/…
 */
final class TreatmentEpisodesPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'treatment-episodes';

    protected static string $permission = 'provider.treatment.view';

    protected static string $screen = 'treatment_episodes';

    public string $episode_type = 'OUTPATIENT';

    public ?string $member_ref = null;

    public ?string $policy_id = null;

    public ?string $preauthorization_id = null;

    public ?string $facility_id = null;

    public ?string $started_on = null;

    public ?string $diagnosis_summary = null;

    public ?string $attending_practitioner = null;

    public ?string $service_code = null;

    public string $quantity = '1';

    public ?string $unit_price_minor = null;

    public ?string $service_date = null;

    public ?string $performed_by = null;

    public ?string $ended_on = null;

    public ?string $invoice_reference = null;

    public function extraView(): ?string
    {
        return 'provider-workspace.episode-form';
    }

    /** @return list<string> */
    public function episodeTypes(): array
    {
        return ProviderOperationsService::EPISODE_TYPES;
    }

    public function openEpisode(): void
    {
        $res = $this->callWorkspace('episodeStore', array_filter(['episode_type' => $this->episode_type, 'member_ref' => $this->member_ref, 'policy_id' => $this->policy_id,
            'preauthorization_id' => $this->preauthorization_id, 'facility_id' => $this->facility_id, 'started_on' => $this->started_on,
            'diagnosis_summary' => $this->diagnosis_summary, 'attending_practitioner' => $this->attending_practitioner], fn ($v) => $v !== null && $v !== ''));
        if ($res !== null) {
            $this->selected = $res['id'] ?? null;
            $this->diagnosis_summary = $this->attending_practitioner = $this->member_ref = null;
        }
    }

    public function addLine(): void
    {
        if ($this->selected && $this->callWorkspace('episodeLine', array_filter(['service_code' => $this->service_code, 'quantity' => $this->quantity,
            'unit_price_minor' => $this->unit_price_minor, 'service_date' => $this->service_date, 'performed_by' => $this->performed_by], fn ($v) => $v !== null && $v !== ''), $this->selected) !== null) {
            $this->service_code = $this->unit_price_minor = $this->performed_by = null;
            $this->quantity = '1';
        }
    }

    public function closeEpisode(): void
    {
        if ($this->selected) {
            $this->callWorkspace('episodeClose', array_filter(['ended_on' => $this->ended_on]), $this->selected);
        }
    }

    public function billEpisode(): void
    {
        if ($this->selected) {
            $this->callWorkspace('episodeBill', ['invoice_reference' => (string) $this->invoice_reference], $this->selected);
        }
    }

    public function selectedStatus(): ?string
    {
        return $this->selected ? (string) rescue(fn () => $this->ops()->episode($this->tenantId(), $this->user(), $this->scope(), $this->selected)->status, '', false) : null;
    }

    public function rowActions(array $row): array
    {
        return isset($row['id']) ? [['label' => __('provider_workspace.ui.open'), 'action' => 'open', 'arg' => $row['id']]] : [];
    }

    protected function detail(): ?array
    {
        $e = (array) $this->ops()->episode($this->tenantId(), $this->user(), $this->scope(), $this->selected);

        return ['title' => __('provider_workspace.screens.treatment_episodes').' '.($e['episode_number'] ?? ''),
            'cards' => array_intersect_key($e, array_flip(['episode_number', 'episode_type', 'status', 'member_ref', 'started_on', 'ended_on', 'health_provider_claim_id'])),
            'rows' => array_map(fn ($l) => array_intersect_key((array) $l, array_flip(['line_no', 'service_code', 'service_name', 'quantity', 'unit_price_minor', 'service_date', 'performed_by'])), $e['lines'] ?? [])];
    }

    protected function rows(): array
    {
        return $this->ops()->episodes($this->tenantId(), $this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
