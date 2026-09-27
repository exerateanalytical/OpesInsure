{{-- Reconciliation: record a payment received; allocate the opened payment to claims. --}}
@php($f = 'provider-workspace.field')
@if ($this->allows('provider.reconciliation.match'))
    <x-filament::section collapsible :collapsed="$this->selected !== null" data-form="reconciliation-record">
        <x-slot name="heading">{{ __('provider_workspace.ui.record_payment') }}</x-slot>
        <form wire:submit="recordPayment" class="grid gap-3 md:grid-cols-3">
            @include($f, ['model' => 'payment_reference', 'label' => 'ui.payment_reference', 'required' => true])
            @include($f, ['model' => 'received_on', 'label' => 'ui.received_on', 'type' => 'date', 'required' => true])
            @include($f, ['model' => 'currency', 'label' => 'ui.currency', 'required' => true])
            @include($f, ['model' => 'amount_minor', 'label' => 'ui.amount_minor', 'type' => 'number', 'required' => true])
            @include($f, ['model' => 'settlement_batch_id', 'label' => 'ui.settlement_batch_id'])
            <div class="md:col-span-3"><x-filament::button type="submit">{{ __('provider_workspace.ui.record_payment') }}</x-filament::button></div>
        </form>
    </x-filament::section>
    @php($open = $this->unallocated())
    @if ($open > 0)
        <x-filament::section data-form="reconciliation-match">
            <x-slot name="heading">{{ __('provider_workspace.ui.match_payment') }} ({{ number_format($open) }})</x-slot>
            <form wire:submit="allocate" class="grid gap-3 md:grid-cols-4 items-end">
                @include($f, ['model' => 'claim_id', 'label' => 'ui.claim_id', 'type' => 'select', 'options' => $this->claimOptions()])
                @include($f, ['model' => 'allocation_minor', 'label' => 'ui.amount_minor', 'type' => 'number', 'required' => true])
                @include($f, ['model' => 'reason', 'label' => 'ui.reason', 'required' => true])
                <div><x-filament::button type="submit">{{ __('provider_workspace.ui.allocate') }}</x-filament::button></div>
            </form>
        </x-filament::section>
    @endif
@endif
