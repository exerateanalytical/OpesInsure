{{-- HTML/CSS phone showing the app home screen (no photography). --}}
@php $tileIcons = ['motor', 'health', 'travel', 'home', 'business', 'life', 'dots']; $tabIcons = ['home', 'doc', 'shield', 'user']; @endphp
<div class="phone {{ $class ?? '' }}" role="img" aria-label="{{ __('site.hero.phone_label') }}">
  <div class="screen">
    <div class="notch"></div>
    <div class="app-top">
      <div class="status" style="padding:0 6px"><span>9:41</span><span aria-hidden="true">▮▮▮</span></div>
      <div class="row"><span class="brand">@include('public.partials.img', ['name' => 'app-icon', 'sizes' => '24px'])<span>Opes<b>Insure</b></span></span>@include('public.partials.i', ['n' => 'bell', 'c' => 'bell'])</div>
      <h4>{{ __('site.phone.greeting') }}</h4>
      <div class="search">@include('public.partials.i', ['n' => 'search']){{ __('site.phone.search') }}</div>
    </div>
    <div class="tiles">
      @foreach(__('site.phone.tiles') as $i => $label)
        <div class="tile {{ $i === 6 ? 'more' : '' }}">@include('public.partials.i', ['n' => $tileIcons[$i]]){{ $label }}</div>
      @endforeach
    </div>
    <div class="tabs">
      @foreach(__('site.phone.tabs') as $i => $label)
        <span class="{{ $i === 0 ? 'on' : '' }}">@include('public.partials.i', ['n' => $tabIcons[$i]]){{ $label }}</span>
      @endforeach
    </div>
  </div>
</div>
