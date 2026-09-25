<x-filament-panels::page>
    <div class="flex items-center gap-3">
        <label for="cima-carrier" class="text-sm font-medium">Insurer</label>
        <select id="cima-carrier" wire:model.live="carrierId" class="rounded-lg border-gray-300 text-sm">
            @foreach ($carriers as $id => $label)
                <option value="{{ $id }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @if (! $setup)
        <x-filament::section><div class="text-sm text-gray-500">No insurer yet.</div></x-filament::section>
    @else
        @php($a = $setup['INS-SET-CIMA-001'])
        <x-filament::section heading="INS-SET-CIMA-001 · CIMA authorization (agrément)" description="Recorded from regulator evidence and approved by a second admin through the approvals engine. The official register below feeds the record but never grants branches by itself.">
            @if ($a['unverified'])
                <div class="mb-3 rounded-lg bg-warning-50 p-3 text-sm text-warning-700">No ACTIVE authorization recorded (owner question Q2 open). New product versions of this insurer stay blocked; products already on sale are grandfathered.</div>
            @endif
            <div class="text-xs font-semibold uppercase text-gray-500">Official register (source)</div>
            @forelse ($a['register_source'] as $r)
                <div class="py-1 text-sm">{{ $r['reference_year'] }} · {{ $r['branch'] }} · {{ $r['status'] }} · {{ $r['source_authority'] }}</div>
            @empty
                <div class="py-1 text-sm text-gray-500">Not on an official register.</div>
            @endforelse
            <div class="mt-3 text-xs font-semibold uppercase text-gray-500">Recorded authorizations</div>
            @forelse ($a['authorizations'] as $r)
                <div class="border-b border-gray-100 py-1 text-sm">{{ $r['reference'] }} · {{ $r['licence_family'] }} · <span class="font-medium">{{ $r['status'] }}</span> · {{ $r['effective_from'] }} → {{ $r['effective_until'] ?? 'open' }} · {{ $r['source'] }}@if ($r['register_year']) · register {{ $r['register_year'] }}@endif @if ($r['source_document']) · {{ $r['source_document'] }}@endif</div>
            @empty
                <div class="py-1 text-sm text-gray-500">None recorded.</div>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="INS-SET-CIMA-002 · Authorized branches (Art. 328)">
            <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                @foreach ($setup['INS-SET-CIMA-002'] as $b)
                    <div class="text-sm">{{ $b['number'] }}. {{ $b['label'] }} <span class="text-xs text-gray-500">({{ $b['family'] }})</span> — @if ($b['authorized'])<span class="text-success-600">authorized</span>@else<span class="text-gray-400">not authorized</span>@endif</div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section heading="INS-SET-CIMA-003 · Product → CIMA mapping" description="Change mappings under CIMA Regulatory Dictionary → Product mapping (maker-checker).">
            @forelse ($setup['INS-SET-CIMA-003'] as $p)
                <div class="border-b border-gray-100 py-1 text-sm">
                    <span class="font-medium">{{ $p['code'] }}</span> ({{ $p['status'] }}) · PRIMARY: {{ implode(', ', $p['primary']) ?: 'none' }}@if ($p['pending']) · {{ $p['pending'] }} pending @endif
                    @if ($p['violations'])<ul class="list-disc pl-5 text-xs text-danger-600">@foreach ($p['violations'] as $v)<li>{{ $v }}</li>@endforeach</ul>@endif
                </div>
            @empty
                <div class="text-sm text-gray-500">No products.</div>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="INS-SET-CIMA-004 · Reporting mapping (Art. 411)">
            @forelse ($setup['INS-SET-CIMA-004'] as $m)
                <div class="py-1 text-sm">{{ $m['subject_type'] }} {{ $m['subject_code'] }} → {{ $m['reporting_category_code'] }} · {{ $m['status'] }} · {{ $m['effective_from'] }} → {{ $m['effective_until'] ?? 'open' }}</div>
            @empty
                <div class="text-sm text-gray-500">Uses the platform class mappings (PLT-CIMA-012). Add an insurer-specific mapping with “Add reporting mapping”.</div>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="INS-SET-CIMA-005 · Accessory risk mapping (Art. 328-1)" description="Branches 14 and 15 can never be accessory.">
            @forelse ($setup['INS-SET-CIMA-005'] as $p)
                <div class="py-1 text-sm">{{ $p['code'] }} · {{ implode(', ', $p['accessory']) }}</div>
            @empty
                <div class="text-sm text-gray-500">No accessory mappings.</div>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="INS-SET-CIMA-006 · Life complementary covers (branches 20/21)">
            @forelse ($setup['INS-SET-CIMA-006'] as $p)
                <div class="py-1 text-sm">{{ $p['code'] }} · PRIMARY {{ implode(', ', $p['primary']) }} · COMPLEMENTARY {{ implode(', ', $p['complementary']) ?: 'none' }}</div>
            @empty
                <div class="text-sm text-gray-500">No life products.</div>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="Name / brand history" collapsible collapsed>
            @foreach ($setup['carrier']['name_history'] as $h)
                <div class="py-1 text-sm">{{ $h['legal_name'] }} @if ($h['trade_name'])({{ $h['trade_name'] }})@endif · {{ \Illuminate\Support\Carbon::parse($h['effective_from'])->toDateString() }} → {{ $h['effective_until'] ? \Illuminate\Support\Carbon::parse($h['effective_until'])->toDateString() : 'current' }} · {{ $h['source'] }}</div>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
