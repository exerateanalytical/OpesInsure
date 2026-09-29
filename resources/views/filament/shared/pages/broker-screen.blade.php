<x-filament-panels::page>
    @php($kpis = $this->kpis())
    @if (count($kpis) > 0)
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:0.75rem">
            @foreach ($kpis as $kpi)
                <div class="fi-section" style="padding:0.875rem 1rem;border-radius:0.75rem;background:#fff;border:1px solid rgba(15,23,42,0.08)">
                    <div style="font-size:0.75rem;color:#64748b;text-transform:uppercase;letter-spacing:0.03em">{{ $kpi['label'] }}</div>
                    <div style="font-size:1.5rem;font-weight:600;margin-top:0.25rem;font-variant-numeric:tabular-nums">{{ $kpi['value'] }}</div>
                </div>
            @endforeach
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
