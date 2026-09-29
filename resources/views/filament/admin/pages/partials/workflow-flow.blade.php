<div class="space-y-4 text-sm">
    <div>
        <div class="font-semibold">{{ __('admin_screens.columns.states') }}</div>
        <p class="mt-1">{{ $states === [] ? '—' : implode(' · ', $states) }}</p>
    </div>
    <div>
        <div class="font-semibold">{{ __('admin_screens.columns.transitions') }}</div>
        <ul class="mt-1 list-disc ps-5">
            @forelse ($transitions as $t)
                <li>{{ $t }}</li>
            @empty
                <li>—</li>
            @endforelse
        </ul>
    </div>
    <div>
        <div class="font-semibold">{{ __('admin_screens.columns.sla_policies') }}</div>
        <ul class="mt-1 list-disc ps-5">
            @forelse ($sla as $s)
                <li>{{ $s }}</li>
            @empty
                <li>—</li>
            @endforelse
        </ul>
    </div>
</div>
