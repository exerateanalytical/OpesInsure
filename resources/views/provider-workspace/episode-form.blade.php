{{-- Treatment episodes: open an episode; on the opened one add services, close it and generate the claim. --}}
@php($f = 'provider-workspace.field')
@if ($this->allows('provider.treatment.update'))
    <x-filament::section collapsible :collapsed="$this->selected !== null" data-form="episode-open">
        <x-slot name="heading">{{ __('provider_workspace.ui.new_episode') }}</x-slot>
        <form wire:submit="openEpisode" class="grid gap-3 md:grid-cols-3">
            @include($f, ['model' => 'episode_type', 'label' => 'ui.episode_type', 'type' => 'select', 'required' => true, 'options' => array_combine($this->episodeTypes(), $this->episodeTypes())])
            @include($f, ['model' => 'member_ref', 'label' => 'ui.member_ref'])
            @include($f, ['model' => 'policy_id', 'label' => 'ui.policy_id'])
            @include($f, ['model' => 'preauthorization_id', 'label' => 'ui.preauth_id'])
            @include($f, ['model' => 'facility_id', 'label' => 'ui.facility', 'type' => 'select', 'options' => $this->facilityOptions()])
            @include($f, ['model' => 'started_on', 'label' => 'ui.started_on', 'type' => 'date'])
            @include($f, ['model' => 'attending_practitioner', 'label' => 'ui.attending_practitioner'])
            @include($f, ['model' => 'diagnosis_summary', 'label' => 'ui.diagnosis_summary', 'type' => 'textarea', 'span' => 'md:col-span-2'])
            <div class="md:col-span-3"><x-filament::button type="submit">{{ __('provider_workspace.ui.open_episode') }}</x-filament::button></div>
        </form>
    </x-filament::section>
    @php($status = $this->selectedStatus())
    @if ($status === 'OPEN')
        <x-filament::section data-form="episode-line">
            <x-slot name="heading">{{ __('provider_workspace.ui.add_service') }}</x-slot>
            <form wire:submit="addLine" class="grid gap-3 md:grid-cols-5 items-end">
                @include($f, ['model' => 'service_code', 'label' => 'ui.service_code', 'required' => true])
                @include($f, ['model' => 'quantity', 'label' => 'ui.quantity', 'type' => 'number'])
                @include($f, ['model' => 'unit_price_minor', 'label' => 'ui.unit_price_minor', 'type' => 'number', 'required' => true])
                @include($f, ['model' => 'service_date', 'label' => 'ui.service_date', 'type' => 'date'])
                @include($f, ['model' => 'performed_by', 'label' => 'ui.performed_by'])
                <div><x-filament::button type="submit">{{ __('provider_workspace.ui.add_service') }}</x-filament::button></div>
            </form>
        </x-filament::section>
        <x-filament::section collapsible collapsed data-form="episode-close">
            <x-slot name="heading">{{ __('provider_workspace.ui.close_episode') }}</x-slot>
            <form wire:submit="closeEpisode" class="flex flex-wrap items-end gap-3">
                @include($f, ['model' => 'ended_on', 'label' => 'ui.ended_on', 'type' => 'date'])
                <x-filament::button type="submit" wire:confirm="{{ __('provider_workspace.ui.confirm') }}">{{ __('provider_workspace.ui.close_episode') }}</x-filament::button>
            </form>
        </x-filament::section>
    @endif
    @if ($status === 'CLOSED' && $this->allows('provider.claim.create'))
        <x-filament::section data-form="episode-bill">
            <x-slot name="heading">{{ __('provider_workspace.ui.bill_episode') }}</x-slot>
            <form wire:submit="billEpisode" class="flex flex-wrap items-end gap-3">
                @include($f, ['model' => 'invoice_reference', 'label' => 'ui.invoice_reference', 'required' => true])
                <x-filament::button type="submit">{{ __('provider_workspace.ui.bill_episode') }}</x-filament::button>
            </form>
        </x-filament::section>
    @endif
@endif
