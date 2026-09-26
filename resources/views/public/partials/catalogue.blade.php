{{--
  Shared body of the marketplace hub and the seven category directories:
  category nav, filter rail, product list, right rail. Inputs: $slug (directory
  line or null), $cat, $filters, $products, $total, $page, $pages, $counts,
  $providerFacet, $compare, $variant ('market'|'directory').
--}}
@php
  $M = \App\Interfaces\Http\Controllers\Web\PublicMarketplace::class;
  $base = $slug ? '/insurance/'.$slug : '/insurance';
  $lineName = $slug ? __('desk.lines.'.$slug)[0] : null;
  $keep = fn (array $over = []) => $base.'?'.http_build_query(array_filter(array_merge([
      'cat' => $slug ? null : $cat, 'q' => $filters['q'] ?: null, 'provider' => $filters['providers'] ?: null,
      'price' => $filters['price'] ?: null, 'sort' => $filters['sort'] !== 'popular' ? $filters['sort'] : null,
  ], $over), fn ($v) => $v !== null && $v !== ''));
  $from = $total ? ($page - 1) * $M::PER_PAGE + 1 : 0;
  $to = min($total, $page * $M::PER_PAGE);
  $icons = ['motor' => 'motor', 'life' => 'life', 'health' => 'health', 'home' => 'home', 'travel' => 'travel', 'accident' => 'accident', 'business' => 'business'];
@endphp

{{-- CATEGORY NAV --}}
<nav class="catnav {{ $variant === 'market' ? 'tiles' : 'tabs' }}" aria-label="{{ __('desk.list.category') }}">
  <div class="wrap-x">
    @foreach($icons as $key => $icon)
      @php $active = ($slug ?? $cat) === $key; @endphp
      <a href="{{ $variant === 'market' ? '/insurance?cat='.$key : '/insurance/'.$key }}" @if($active) aria-current="page" @endif>
        @include('public.partials.i', ['n' => $icon])<span>{{ $variant === 'market' ? __('desk.lines.'.$key)[0] : __('desk.tab.'.$key) }}</span>
      </a>
    @endforeach
    <a href="/insurance" @if(!$slug && !$cat) aria-current="page" @endif>@include('public.partials.i', ['n' => 'grid'])<span>{{ __('desk.tab.all') }}</span></a>
  </div>
</nav>

<div class="wrap-x cat-grid">
  {{-- FILTER RAIL --}}
  <aside class="panel rail">
    <form id="cat-filters" method="get" action="{{ $base }}" class="js-autosubmit">
      <div class="rail-head"><h2>{{ $slug ? __('desk.list.filter_line', ['line' => $lineName]) : __('desk.list.filter') }}</h2><a href="{{ $base }}">{{ __('desk.list.reset') }}</a></div>
      @if($filters['q'] !== '')<input type="hidden" name="q" value="{{ $filters['q'] }}">@endif
      <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
      @if(!$slug)
        <fieldset><legend>{{ __('desk.list.category') }} @include('public.partials.i', ['n' => 'chev-down'])</legend>
          <label class="opt"><input type="radio" name="cat" value="" @checked(!$cat)><span>{{ __('desk.list.any') }}</span><em>{{ array_sum($counts) }}</em></label>
          @foreach($icons as $key => $icon)
            <label class="opt"><input type="radio" name="cat" value="{{ $key }}" @checked($cat === $key)><span>{{ __('desk.tab.'.$key) }}</span><em>{{ $counts[$key] }}</em></label>
          @endforeach
        </fieldset>
      @endif
      <fieldset><legend>{{ __('desk.list.provider') }} @include('public.partials.i', ['n' => 'chev-down'])</legend>
        @if(count($providerFacet) > 6)<input class="rail-search" type="search" placeholder="{{ __('desk.list.search_providers') }}" aria-label="{{ __('desk.list.search_providers') }}" data-filter-list="prov-list">@endif
        <div id="prov-list">
        @forelse($providerFacet as $key => $pf)
          <label class="opt" data-name="{{ mb_strtolower($pf['name']) }}"><input type="checkbox" name="provider[]" value="{{ $key }}" @checked(in_array($key, $filters['providers'], true))><span>{{ $pf['name'] }}</span><em>{{ $pf['n'] }}</em></label>
        @empty
          <p class="muted-s">—</p>
        @endforelse
        </div>
      </fieldset>
      <fieldset><legend>{{ __('desk.list.premium') }} @include('public.partials.i', ['n' => 'chev-down'])</legend>
        <label class="opt"><input type="radio" name="price" value="" @checked($filters['price'] === '')><span>{{ __('desk.list.any') }}</span></label>
        @foreach(__('desk.list.bands') as $k => $label)
          <label class="opt"><input type="radio" name="price" value="{{ $k }}" @checked($filters['price'] === $k)><span>{{ $label }}</span></label>
        @endforeach
      </fieldset>
      <noscript><button class="dbtn dbtn-primary" type="submit" style="width:100%;margin-top:12px">{{ __('desk.list.apply') }}</button></noscript>
    </form>
  </aside>

  {{-- PRODUCT LIST --}}
  <section class="plist" aria-labelledby="plist-title">
    <div class="plist-head">
      <h2 id="plist-title">{{ $slug ? __('desk.list.count_line', ['n' => $total, 'line' => $lineName]) : __('desk.list.count', ['n' => $total]) }}</h2>
      <label class="sortby">{{ __('desk.list.sort_by') }}
        <select name="sort" form="cat-sort" onchange="this.form.submit()">
          @foreach(__('desk.list.sorts') as $k => $label)<option value="{{ $k }}" @selected($filters['sort'] === $k)>{{ $label }}</option>@endforeach
        </select>
      </label>
      <form id="cat-sort" method="get" action="{{ $base }}" hidden>
        @if(!$slug && $cat)<input type="hidden" name="cat" value="{{ $cat }}">@endif
        @if($filters['q'] !== '')<input type="hidden" name="q" value="{{ $filters['q'] }}">@endif
        @foreach($filters['providers'] as $pv)<input type="hidden" name="provider[]" value="{{ $pv }}">@endforeach
        @if($filters['price'])<input type="hidden" name="price" value="{{ $filters['price'] }}">@endif
      </form>
      <span class="viewtg" role="group"><button type="button" data-view="rows" aria-pressed="true" aria-label="{{ __('desk.list.view_rows') }}">@include('public.partials.i', ['n' => 'grid'])</button><button type="button" data-view="compact" aria-pressed="false" aria-label="{{ __('desk.list.view_compact') }}">@include('public.partials.i', ['n' => 'list'])</button></span>
    </div>

    @if($filters['q'] !== '')
      <p class="qchip">“{{ $filters['q'] }}” <a href="{{ $keep(['q' => null, 'page' => null]) }}" aria-label="{{ __('desk.list.reset') }}">@include('public.partials.i', ['n' => 'x'])</a></p>
    @endif

    <ul class="prows">
      @forelse($products as $p)
        @php
          $main = collect($p['covers'])->reject(fn ($cov) => $cov['optional']);
          $desc = $main->take(3)->pluck('name')->implode(', ');
        @endphp
        <li class="prow">
          <div class="pr-logo">@include('public.partials.pmark', ['p' => $p])</div>
          <div class="pr-main">
            <h3><a href="/compare?p[]={{ urlencode($p['code']) }}">{{ $p['name'] }}</a></h3>
            @if($variant === 'market')
              <p class="tags"><span>{{ __('desk.tab.'.$p['slug']) }}</span><span>{{ $p['carrier'] }}</span></p>
              <p class="pdesc">{{ trans_choice('desk.list.covers_n', count($p['covers']), ['n' => count($p['covers'])]) }}@if($desc) — {{ $desc }}@endif.</p>
            @else
              <p class="pdesc">{{ $desc }}@if($main->count() > 3) …@endif</p>
              <p class="tags">@foreach(collect($p['covers'])->take(3) as $cov)<span>{{ $cov['name'] }}@if($cov['optional']) · {{ __('desk.list.optional') }}@endif</span>@endforeach</p>
            @endif
          </div>
          @if($variant === 'market')
            <ul class="pr-checks">@foreach(collect($p['covers'])->take(4) as $cov)<li>@include('public.partials.i', ['n' => 'check']){{ $cov['name'] }}</li>@endforeach</ul>
          @endif
          <div class="pr-cover">
            @if($p['cover_max'])<small>{{ __('desk.list.cover_upto') }}</small><b>{{ $M::money($p['cover_max']) }}</b>@endif
            @if($variant !== 'market' && $main->first())<small class="mt">{{ __('desk.list.key_benefit') }}</small><span>{{ $main->first()['name'] }}</span>@endif
            @if($variant === 'market')
              <small class="mt">{{ __('desk.list.from') }}</small>
              @if($p['premium'])<b class="big">{{ $M::money($p['premium']) }}</b><small>{{ __('desk.list.per_year') }}</small>@else<b>{{ __('desk.list.on_request') }}</b>@endif
            @endif
          </div>
          @if($variant !== 'market')
            <div class="pr-price">
              <small>{{ __('desk.list.from') }}</small>
              @if($p['premium'])<b>{{ $M::money($p['premium']) }}</b><small>{{ __('desk.list.per_year') }}</small>@else<b class="blue">{{ __('desk.list.on_request') }}</b>@endif
            </div>
          @endif
          <div class="pr-act">
            <a class="dbtn {{ $variant === 'market' ? 'dbtn-outline' : 'dbtn-primary' }}" href="/compare?p[]={{ urlencode($p['code']) }}">{{ __('desk.list.view') }}@if($variant !== 'market') @include('public.partials.i', ['n' => 'arrow'])@endif</a>
            @if($variant === 'market')<a class="dbtn dbtn-primary" href="{{ url('/app/compare?product='.urlencode($p['code'])) }}">{{ __('desk.list.quote') }}</a>@endif
            <label class="cmp"><input type="checkbox" name="p[]" value="{{ $p['code'] }}" form="cmp-form" data-cmp @checked(in_array($p['code'], $compare, true))> {{ __('desk.list.compare') }}</label>
          </div>
        </li>
      @empty
        <li class="prow empty"><p>{{ array_sum($counts) ? __('desk.list.empty') : __('desk.list.empty_all') }}</p>@if(array_sum($counts))<a class="dbtn dbtn-outline" href="{{ $base }}">{{ __('desk.list.reset') }}</a>@else<a class="dbtn dbtn-primary" href="/contact">{{ __('desk.list.contact') }}</a>@endif</li>
      @endforelse
    </ul>

    <div class="plist-foot">
      <span>{{ __('desk.list.showing', ['from' => $from, 'to' => $to, 'n' => $total]) }}</span>
      @if($pages > 1)
        <nav class="pager" aria-label="Pagination">
          @if($page > 1)<a href="{{ $keep(['page' => $page - 1]) }}" aria-label="{{ __('desk.list.prev') }}">@include('public.partials.i', ['n' => 'chev-left'])</a>@endif
          @for($i = 1; $i <= $pages; $i++)
            <a href="{{ $keep(['page' => $i]) }}" @if($i === $page) aria-current="page" @endif>{{ $i }}</a>
          @endfor
          @if($page < $pages)<a href="{{ $keep(['page' => $page + 1]) }}" aria-label="{{ __('desk.list.next') }}">@include('public.partials.i', ['n' => 'chev'])</a>@endif
        </nav>
      @endif
      <span class="muted-s">{{ __('desk.list.note') }}</span>
    </div>
  </section>

  {{-- RIGHT RAIL --}}
  <aside class="rrail">
    @if($variant === 'market')
      <form id="cmp-form" class="panel side-card" method="get" action="/compare">
        <h2>@include('public.partials.i', ['n' => 'scale']){{ __('desk.list.cmp_t') }}</h2>
        <p>{{ __('desk.list.cmp_d') }}</p>
        <button class="dbtn dbtn-primary wide" type="submit">{{ __('desk.list.cmp_btn') }} <span data-cmp-count></span> @include('public.partials.i', ['n' => 'arrow'])</button>
      </form>
      <div class="panel side-card warm">
        <h2>@include('public.partials.i', ['n' => 'bulb']){{ __('desk.list.help_t') }}</h2>
        <p>{{ __('desk.list.help_d') }}</p>
        <a class="dbtn dbtn-outline wide" href="/contact?topic=support">{{ __('desk.list.help_btn') }} @include('public.partials.i', ['n' => 'arrow'])</a>
      </div>
      <div class="panel side-card">
        <h2>{{ __('desk.market.why_t') }}</h2>
        <ul class="why-list">@foreach(__('desk.market.why') as $i => $w)<li>@include('public.partials.i', ['n' => ['compare', 'doc', 'lock', 'doc', 'shield', 'refresh', 'headset'][$i]]){{ $w }}</li>@endforeach</ul>
      </div>
    @else
      <div class="panel side-card">
        <h2>{{ __('desk.list.why_t', ['line' => $lineName]) }}</h2>
        <ul class="why-dots">@foreach(__('desk.lines.'.$slug)[3] as $i => $w)<li><span class="d d{{ $i }}">@include('public.partials.i', ['n' => ['shield', 'motor', 'users', 'star', 'health'][$i]])</span>{{ $w }}</li>@endforeach</ul>
      </div>
      <div class="panel side-card">
        <h2>{{ __('desk.list.need_t') }}</h2>
        <div class="need"><img src="/landing/img/desk/agent.webp" alt="" width="94" height="124" loading="lazy"><div><p>{{ __('desk.list.need_d') }}</p><a class="dbtn dbtn-outline" href="/contact?topic=support">{{ __('desk.list.contact') }} @include('public.partials.i', ['n' => 'arrow'])</a></div></div>
      </div>
      <div class="panel side-card">
        <h2>{{ __('desk.list.learn') }}</h2>
        <ul class="learn">@foreach(__('desk.lines.'.$slug)[4] as $i => $l)<li><a href="{{ $i === 3 ? '/faq' : '/how-it-works' }}">{{ $l }} @include('public.partials.i', ['n' => 'chev'])</a></li>@endforeach</ul>
      </div>
      <form id="cmp-form" class="panel side-card" method="get" action="/compare">
        <h2>@include('public.partials.i', ['n' => 'scale']){{ __('desk.list.cmp_t') }}</h2>
        <p>{{ __('desk.list.cmp_d') }}</p>
        <button class="dbtn dbtn-primary wide" type="submit">{{ __('desk.list.cmp_btn') }} <span data-cmp-count></span> @include('public.partials.i', ['n' => 'arrow'])</button>
      </form>
    @endif
  </aside>
</div>
