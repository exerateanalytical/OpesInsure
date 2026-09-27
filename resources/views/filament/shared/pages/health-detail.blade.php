{{-- Read-only detail of a health work item: summary cards, lines and history (service payload). --}}
<div class="space-y-4 text-sm" data-detail="health">
    <dl class="grid gap-2 md:grid-cols-3">
        @foreach ($cards as $k => $v)
            <div class="rounded-lg border border-gray-200 p-2 dark:border-white/10">
                <dt class="text-xs text-gray-500">{{ str_replace('_', ' ', ucfirst($k)) }}</dt>
                <dd class="font-medium">{{ is_scalar($v) || $v === null ? ($v ?? '—') : json_encode($v) }}</dd>
            </div>
        @endforeach
    </dl>
    @foreach (['lines' => $lines, 'history' => $history] as $title => $rows)
        @if ($rows !== [])
            <h3 class="font-semibold">{{ __('workflow_actions.screens.'.$title) }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead><tr>@foreach (array_keys($rows[0]) as $c)<th class="px-2 py-1 text-start">{{ str_replace('_', ' ', $c) }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">@foreach ($row as $v)<td class="px-2 py-1">{{ $v }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach
</div>
