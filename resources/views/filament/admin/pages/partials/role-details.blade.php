<div class="space-y-4 text-sm">
    <p>{{ __('admin_screens.role_details.scope', ['scope' => $scope]) }}</p>
    @forelse ($groups as $category => $perms)
        <div>
            <div class="font-semibold">{{ $category }} ({{ count($perms) }})</div>
            <ul class="mt-1 list-disc ps-5">
                @foreach ($perms as $p)
                    <li><code>{{ $p['code'] }}</code>@if ($p['description']) <span class="text-gray-500">— {{ $p['description'] }}</span>@endif</li>
                @endforeach
            </ul>
        </div>
    @empty
        <p class="text-gray-500">{{ __('admin_screens.role_details.none') }}</p>
    @endforelse
</div>
