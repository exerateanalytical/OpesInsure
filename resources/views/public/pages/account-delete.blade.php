@extends('public.layout')
@section('title', __('site.delete.title').' — OpesInsure')
@section('description', __('site.delete.lede'))
@section('content')
@include('public.partials.page-hero', ['title' => __('site.delete.title'), 'lede' => __('site.delete.lede')])
<section><div class="wrap two-col">
  <div>
    <div class="card">
      <h2 style="font-size:20px">{{ __('site.delete.app_title') }}</h2>
      <ol style="padding-left:20px;margin:12px 0 0">@foreach(__('site.delete.app_steps') as $s)<li style="margin-bottom:6px">{{ $s }}</li>@endforeach</ol>
    </div>
    <div class="card form-card" style="margin-top:20px">
      <h2 style="font-size:20px">{{ __('site.delete.web_title') }}</h2>
      @if(session('deletion_received'))
        <div class="note ok" role="status" style="margin-top:14px">{{ __('site.delete.received') }}</div>
      @endif
      @if($errors->any())<div class="note" role="alert" style="margin-top:14px">{{ __('site.errors.check') }}</div>@endif
      <form method="post" action="/account/delete" novalidate style="margin-top:16px">
        @csrf
        <div class="hp" aria-hidden="true"><label for="d-website">Website</label><input id="d-website" type="text" name="website" tabindex="-1" autocomplete="off"></div>
        <div class="form-grid">
          <div class="field full">
            <label for="d-identifier">{{ __('site.delete.identifier') }}</label>
            <input id="d-identifier" name="identifier" value="{{ old('identifier') }}" required maxlength="190" autocomplete="username" aria-describedby="h-identifier @error('identifier') e-identifier @enderror">
            <p class="hint" id="h-identifier">{{ __('site.delete.identifier_hint') }}</p>
            @error('identifier')<p class="err" id="e-identifier">{{ $message }}</p>@enderror
          </div>
          <div class="field full">
            <label for="d-name">{{ __('site.delete.full_name') }}</label>
            <input id="d-name" name="full_name" value="{{ old('full_name') }}" required maxlength="120" autocomplete="name">
            @error('full_name')<p class="err">{{ $message }}</p>@enderror
          </div>
          <div class="field full">
            <label for="d-reason">{{ __('site.delete.reason') }}</label>
            <textarea id="d-reason" name="reason" maxlength="1000" style="min-height:90px">{{ old('reason') }}</textarea>
          </div>
          <div class="full">
            <label class="check"><input type="checkbox" name="confirm" value="1" required @checked(old('confirm'))> <span>{{ __('site.delete.confirm') }}</span></label>
            @error('confirm')<p class="err" style="font-size:13px;color:#B42318;font-weight:600">{{ $message }}</p>@enderror
          </div>
        </div>
        <button class="btn btn-gold" type="submit" style="margin-top:18px">@include('public.partials.i', ['n' => 'trash']) {{ __('site.delete.submit') }}</button>
      </form>
    </div>
  </div>
  <aside class="card">
    <h2 style="font-size:20px">{{ __('site.delete.what_title') }}</h2>
    <ul style="padding-left:20px;margin:12px 0 0">@foreach(__('site.delete.what') as $w)<li style="margin-bottom:10px;color:var(--muted)">{{ $w }}</li>@endforeach</ul>
    <p style="margin-top:14px"><a class="link" href="/privacy">{{ __('site.footer.privacy') }} @include('public.partials.i', ['n' => 'arrow'])</a></p>
    @if($contacts['email'])<p style="margin-top:8px"><a class="link" href="mailto:{{ $contacts['email'] }}">{{ $contacts['email'] }}</a></p>@endif
  </aside>
</div></section>
@endsection
