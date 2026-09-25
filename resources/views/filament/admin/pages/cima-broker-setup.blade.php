<x-filament-panels::page>
    <div class="flex items-center gap-3">
        <label for="cima-partner" class="text-sm font-medium">Intermediary</label>
        <select id="cima-partner" wire:model.live="partnerId" class="rounded-lg border-gray-300 text-sm">
            @foreach ($partners as $id => $label)
                <option value="{{ $id }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @if (! $setup)
        <x-filament::section><div class="text-sm text-gray-500">No intermediary yet.</div></x-filament::section>
    @else
        <x-filament::section heading="Register" description="Effective-dated register authorizations; a year is never overwritten.">
            <div class="text-sm">{{ $setup['partner']['name'] }} · {{ $setup['partner']['type'] }} · licence {{ $setup['partner']['licence_number'] ?? '—' }}</div>
            @forelse ($setup['partner']['register'] as $r)
                <div class="py-1 text-sm">{{ $r['reference_year'] }} · {{ $r['intermediary_type'] }} · {{ $r['status'] }} · {{ $r['source_authority'] }}</div>
            @empty
                <div class="py-1 text-sm text-gray-500">Not on an official register.</div>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="BRK-SET-CIMA-001 · Reporting configuration (Art. 557 measures)">
            @foreach ($setup['BRK-SET-CIMA-001'] as $m)
                <div class="py-1 text-sm">{{ $m['label'] }} <span class="text-xs text-gray-500">({{ $m['code'] }})</span> — @if ($m['enabled'])<span class="text-success-600">reported</span>@else<span class="text-gray-400">not configured</span>@endif</div>
            @endforeach
        </x-filament::section>

        @foreach (['BRK-SET-CIMA-002' => 'Regulatory product classification (Art. 411)', 'BRK-SET-CIMA-003' => 'Premium / collection classification', 'BRK-SET-CIMA-004' => 'Commission reporting mapping'] as $screen => $title)
            <x-filament::section :heading="$screen.' · '.$title">
                @forelse ($setup[$screen] as $m)
                    <div class="py-1 text-sm">{{ $m['subject_type'] }} {{ $m['subject_code'] }} → {{ $m['measure_code'] ?? $m['reporting_category_code'] }} · {{ $m['status'] }} · {{ $m['effective_from'] }} → {{ $m['effective_until'] ?? 'open' }}</div>
                @empty
                    <div class="text-sm text-gray-500">Nothing configured. Use “Add reporting setting”.</div>
                @endforelse
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
