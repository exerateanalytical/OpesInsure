<div class="space-y-3 text-sm">
    @php($blocking = (array) ($check['blocking'] ?? []))
    <p @class(['text-danger-600' => $blocking !== [], 'text-success-600' => $blocking === []])>
        {{ $blocking === [] ? __('admin_screens.checklist.clear') : __('admin_screens.checklist.blocked', ['items' => implode(', ', $blocking)]) }}
    </p>
    <ul class="list-disc ps-5">
        @foreach ((array) ($check['items'] ?? []) as $key => $item)
            <li>
                <span class="font-medium">{{ is_string($key) ? $key : ($item['key'] ?? $item['code'] ?? '') }}</span>:
                {{ is_array($item) ? collect($item)->map(fn ($v, $k) => $k.' '.(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)))->implode(' · ') : $item }}
            </li>
        @endforeach
    </ul>
</div>
