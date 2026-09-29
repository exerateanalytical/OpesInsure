{{-- SHR-001 / SHR-002 staff global + advanced search (App\Filament\Shared\Pages\GlobalSearchPage). --}}
<x-filament-panels::page>
    <form wire:submit="search" class="fi-section" style="display:grid;gap:12px;padding:16px;border-radius:12px;background:#fff;border:1px solid #e5e7eb">
        <label style="display:grid;gap:4px">
            <span style="font-weight:600">{{ __('launch_customer.search.label') }}</span>
            <input type="search" wire:model="q" minlength="2" maxlength="100" required placeholder="{{ __('launch_customer.search.placeholder') }}"
                   style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px" data-global-search>
        </label>
        <fieldset style="display:flex;flex-wrap:wrap;gap:12px;border:0;padding:0">
            <legend style="font-weight:600;margin-bottom:4px">{{ __('launch_customer.search.types_label') }}</legend>
            @foreach($this->typeOptions() as $value => $label)
                <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" wire:model="types" value="{{ $value }}">{{ $label }}</label>
            @endforeach
        </fieldset>
        <label style="display:flex;gap:8px;align-items:center">
            <span>{{ __('launch_customer.search.per_type') }}</span>
            <select wire:model="limit" style="padding:4px 8px;border:1px solid #d1d5db;border-radius:8px">
                @foreach([5, 10, 20] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
            </select>
        </label>
        <div><x-filament::button type="submit" icon="lucide-search">{{ __('launch_customer.search.submit') }}</x-filament::button></div>
    </form>

    @if($error)
        <p role="alert" style="color:#b91c1c">{{ $error }}</p>
    @elseif($result !== null)
        @php $hits = collect($result['results'] ?? [])->groupBy('type'); @endphp
        <p style="color:#6b7280">{{ __('launch_customer.search.searched', ['types' => collect($result['searched'] ?? [])->map(fn ($t) => __('launch_customer.search.types.'.$t))->implode(', ') ?: '—']) }}</p>
        @if($hits->isEmpty())
            <p>{{ __('launch_customer.search.none') }}</p>
        @endif
        @foreach($hits as $type => $rows)
            <section style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px">
                <h2 style="font-weight:700;margin-bottom:8px">{{ __('launch_customer.search.types.'.$type) }} ({{ count($rows) }})</h2>
                <ul style="display:grid;gap:8px">
                    @foreach($rows as $hit)
                        @php $url = $this->hitUrl($hit); @endphp
                        <li style="display:flex;justify-content:space-between;gap:12px;border-top:1px solid #f3f4f6;padding-top:8px">
                            <span><b>{{ $hit['title'] ?? $hit['id'] }}</b>@if(!empty($hit['subtitle'])) <small style="color:#6b7280">· {{ $hit['subtitle'] }}</small>@endif</span>
                            <span style="display:flex;gap:8px;align-items:center">
                                @if(!empty($hit['status']))<x-filament::badge>{{ $hit['status'] }}</x-filament::badge>@endif
                                @if($url)<a href="{{ $url }}" style="color:#1d4ed8;text-decoration:underline">{{ __('launch_customer.search.open') }}</a>@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif
</x-filament-panels::page>
