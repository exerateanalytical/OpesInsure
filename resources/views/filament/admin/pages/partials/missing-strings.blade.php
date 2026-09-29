<div class="text-sm">
    <p class="mb-2">{{ __('admin_screens.missing_strings.intro', ['n' => count($keys)]) }}</p>
    <ul class="list-disc ps-5 font-mono">
        @foreach (array_slice($keys, 0, 500) as $k)
            <li>{{ $k }}</li>
        @endforeach
    </ul>
</div>
