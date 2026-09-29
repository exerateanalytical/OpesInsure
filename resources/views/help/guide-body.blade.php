{{-- S11: one guide rendered (table of contents + anchored sections). $guide from HelpGuides::load(), $sections the (searched) subset. --}}
<div class="opes-help">
  @php($guide = $helpGuide ?? $guide){{-- inside a Livewire page the public \$guide property (a string) shadows the include variable --}}
  @if($guide['intro'] !== '')<div class="opes-help-intro">{!! $guide['intro'] !!}</div>@endif
  @if(count($sections) > 1)
    <nav class="opes-help-toc" aria-label="{{ __('help_guides.contents') }}">
      <b>{{ __('help_guides.contents') }}</b>
      <ol>@foreach($sections as $s)<li><a href="#{{ $s['id'] }}">{{ $s['title'] }}</a></li>@endforeach</ol>
    </nav>
  @endif
  @forelse($sections as $s)
    <section id="{{ $s['id'] }}" class="opes-help-section" data-help-section>
      <h2><a href="#{{ $s['id'] }}" class="opes-help-anchor" aria-hidden="true">#</a> {{ $s['title'] }}</h2>
      {!! $s['html'] !!}
    </section>
  @empty
    <p class="opes-help-empty">{{ __('help_guides.no_results') }}</p>
  @endforelse
</div>
