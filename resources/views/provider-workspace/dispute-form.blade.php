{{-- Disputes: open a dispute on a claim, claim line, settlement or reconciliation. --}}
@php($f = 'provider-workspace.field')
@if ($this->allows('provider.dispute.create'))
    <x-filament::section collapsible :collapsed="$this->selected !== null" data-form="dispute-open">
        <x-slot name="heading">{{ __('provider_workspace.ui.new_dispute') }}</x-slot>
        <form wire:submit="openDispute" class="grid gap-3 md:grid-cols-3">
            @include($f, ['model' => 'subject_type', 'label' => 'ui.subject_type', 'type' => 'select', 'required' => true, 'live' => true, 'options' => $this->subjectOptions()])
            @if (in_array($this->subject_type, ['CLAIM', 'CLAIM_LINE'], true))
                @include($f, ['model' => 'claim_id', 'label' => 'ui.claim_id', 'required' => true])
            @endif
            @if ($this->subject_type === 'CLAIM_LINE')
                @include($f, ['model' => 'claim_line_no', 'label' => 'ui.claim_line_no', 'type' => 'number', 'required' => true])
            @endif
            @if ($this->subject_type === 'SETTLEMENT')
                @include($f, ['model' => 'settlement_batch_id', 'label' => 'ui.settlement_batch_id', 'required' => true])
            @endif
            @if ($this->subject_type === 'RECONCILIATION')
                @include($f, ['model' => 'reconciliation_id', 'label' => 'ui.reconciliation_id', 'required' => true])
            @endif
            @include($f, ['model' => 'reason_code', 'label' => 'ui.reason_code', 'type' => 'select', 'required' => true, 'options' => $this->reasonOptions()])
            @include($f, ['model' => 'disputed_amount_minor', 'label' => 'ui.disputed_amount_minor', 'type' => 'number'])
            @include($f, ['model' => 'description', 'label' => 'ui.description', 'type' => 'textarea', 'required' => true, 'span' => 'md:col-span-3'])
            <div class="md:col-span-3"><x-filament::button type="submit">{{ __('provider_workspace.ui.open_dispute') }}</x-filament::button></div>
        </form>
    </x-filament::section>
@endif
