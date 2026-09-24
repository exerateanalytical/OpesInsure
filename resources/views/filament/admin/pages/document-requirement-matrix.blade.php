<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-center gap-3">
            <label class="text-sm font-medium" for="dm-product-type">Product type</label>
            <select id="dm-product-type" wire:model.live="productType" class="rounded-lg border-gray-300 text-sm">
                @foreach ($types as $t)
                    <option value="{{ $t->code }}">{{ $t->code }} — {{ $t->label_fr }}@if ($t->status !== 'ACTIVE') ({{ strtolower($t->status) }})@endif</option>
                @endforeach
            </select>
            @if ($current)
                <span class="text-xs text-gray-500">Section {{ $current->spec_section }} · class {{ $current->class_code }} · inheritance: {{ implode(' → ', array_merge(['BASELINE'], $chain)) }}</span>
            @endif
        </div>
        <div class="mt-2 text-xs text-gray-500">Levels: M mandatory · C conditional · O optional · I internal · T third-party supplied. "C/M" = base C, insurer may make it M.</div>
    </x-filament::section>

    @foreach ($stages as $stage)
        @continue(! isset($grid[$stage]))
        <x-filament::section :heading="str_replace('_', ' ', $stage)">
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-gray-500">
                    <tr><th class="py-1">Document (matrix)</th><th>Register</th><th>Level</th><th>Qualifiers</th><th>Issuer</th><th>Recipient</th><th>Trigger</th><th>Source</th></tr>
                </thead>
                <tbody>
                    @foreach ($grid[$stage] as $r)
                        <tr class="border-t border-gray-100">
                            <td class="py-1">{{ $r['matrix_label'] }}@if ($r['variant_code'])<div class="text-xs text-warning-600">variant {{ $r['variant_code'] }}</div>@endif</td>
                            <td class="font-mono text-xs">{{ $r['document_type_id'] }}<div class="font-sans text-gray-500">{{ $r['label_fr'] }}</div></td>
                            <td><span class="font-semibold">{{ $r['raw_level'] }}</span></td>
                            <td class="text-xs">{{ implode(', ', $r['qualifiers']) }}</td>
                            <td class="text-xs">{{ $r['issuer'] }}</td>
                            <td class="text-xs">{{ $r['recipient'] }}</td>
                            <td class="font-mono text-xs">{{ $r['trigger'] }}</td>
                            <td class="text-xs text-gray-500">{{ $r['source'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endforeach

    <x-filament::section heading="All product types × stages" description="Number of resolved requirements (baseline + inheritance) per stage.">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="text-gray-500"><tr><th class="py-1">Product type</th>@foreach ($stages as $s)<th>{{ str_replace('_', ' ', $s) }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($overview as $o)
                        <tr class="border-t border-gray-100">
                            <td class="py-1 font-mono">{{ $o['code'] }}@if ($o['status'] !== 'ACTIVE') <span class="text-warning-600">({{ strtolower($o['status']) }})</span>@endif</td>
                            @foreach ($stages as $s)<td>{{ $o['counts'][$s] ?? '·' }}</td>@endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
