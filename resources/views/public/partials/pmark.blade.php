@php $dir = \App\Interfaces\Http\Controllers\Web\PublicProviderDirectory::class; @endphp
{{-- Insurer mark: authorized public-display logo when uploaded, otherwise a typographic badge. --}}
<span class="pmark t{{ $dir::tone($p['carrier']) }}">
  @if(!empty($p['logo_url']))<img src="{{ $p['logo_url'] }}" alt="" loading="lazy" onerror="this.replaceWith(Object.assign(document.createElement('i'),{className:'mono',textContent:'{{ $dir::initials($p['carrier']) }}'}))">@else<i class="mono" aria-hidden="true">{{ $dir::initials($p['carrier']) }}</i>@endif
  <b>{{ $p['carrier'] }}</b>
</span>
