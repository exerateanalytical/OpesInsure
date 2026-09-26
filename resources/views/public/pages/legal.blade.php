{{-- Shared body for /privacy and /terms. Terms text reuses the mobile app's draft (mobile app/app/terms.tsx). --}}
@include('public.partials.page-hero', ['title' => __($key.'.title'), 'lede' => __($key.'.lede'), 'img' => 'about-cima'])
<div class="ip">
<section><div class="wrap prose">
  {{-- Owner decision 30: stays DRAFT_LEGAL_REVIEW_REQUIRED until counsel reviews the text. --}}
  <div class="note" role="note" data-legal-status="DRAFT_LEGAL_REVIEW_REQUIRED"><span aria-hidden="true">&#9888;</span><span><strong>{{ __('site.common.draft') }}</strong> <code>DRAFT_LEGAL_REVIEW_REQUIRED</code><br>{{ __('site.common.draft_body') }}</span></div>
  <p style="margin-top:16px;color:var(--muted);font-size:14px">{{ __('site.common.updated', ['date' => '2026-09-25']) }}</p>
  @foreach(__($key.'.sections') as [$t, $d])
    <h2>{{ $t }}</h2><p>{{ $d }}</p>
  @endforeach
  <div class="card" style="margin-top:26px">
    <ul class="contact-list">
      @if($contacts['email'])<li><span class="ico">@include('public.partials.i', ['n' => 'mail'])</span><a class="link" href="mailto:{{ $contacts['email'] }}">{{ $contacts['email'] }}</a></li>@endif
      @if($contacts['phone'])<li><span class="ico">@include('public.partials.i', ['n' => 'phone'])</span><a class="link" href="tel:{{ preg_replace('/[^+\d]/', '', $contacts['phone']) }}">{{ $contacts['phone'] }}</a></li>@endif
      <li><span class="ico">@include('public.partials.i', ['n' => 'chat'])</span><a class="link" href="/contact?topic=privacy">{{ __('site.footer.contact') }}</a></li>
      <li><span class="ico">@include('public.partials.i', ['n' => 'trash'])</span><a class="link" href="/account/delete">{{ __('site.footer.delete') }}</a></li>
    </ul>
  </div>
</div></section>
</div>
