{{-- Q8 underwriting dashboard: UND-001 KPIs, UND-004 my assigned cases, UND-020 performance & SLA. --}}
@php($d = $this->data())
<x-filament-panels::page>
    <div data-testid="uw-dashboard-kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.75rem">
        @foreach ($d['dashboard']['kpis'] as $kpi)
            <x-filament::section>
                <div style="font-size:.75rem;opacity:.7">{{ $kpi['label'] }}</div>
                <div style="font-size:1.5rem;font-weight:700" class="oi-num">{{ $kpi['value'] }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section :heading="__('uw_workbench.assigned_cases')">
        <x-slot name="afterHeader"><a href="{{ $d['listUrl'] }}" class="fi-link" style="color:var(--oi-blue-600);font-weight:600">{{ __('uw_workbench.view_all') }}</a></x-slot>
        @include('filament.shared.uw-workbench-section', ['key' => 'assigned', 'section' => $d['dashboard']['mine'], 'caseUrl' => $d['caseUrl']])
    </x-filament::section>

    <x-filament::section :heading="__('uw_workbench.by_status')">
        @include('filament.shared.uw-workbench-section', ['key' => 'status', 'section' => $d['dashboard']['status']])
    </x-filament::section>

    <x-filament::section :heading="__('uw_workbench.performance')">
        @include('filament.shared.uw-workbench-section', ['key' => 'performance', 'section' => $d['performance']])
    </x-filament::section>
</x-filament-panels::page>
