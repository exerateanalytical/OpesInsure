{{-- Shared status-page blocks of a partner application / organisation claim: flash, code entry, information request, documents.
     Params: $r (record), $base (status URL), $codeHelp (text), $canResend (bool). --}}
@php $P = __('partner_apply'); $scans = app(\App\Application\Partners\Onboarding\PublicIntake::class)->scanStatuses($r->documents ?? []); @endphp
@if(session('status_ok'))<div class="note ok" role="status">{{ session('status_ok') }}</div>@endif
@if(session('status_error'))<div class="note" role="alert">{{ session('status_error') }}</div>@endif
@if($errors->any())<div class="note" role="alert">@foreach($errors->all() as $m){{ $m }}<br>@endforeach</div>@endif

@if($r->status === 'UNVERIFIED')
  <div class="card form-card" style="margin-top:16px">
    <h2 style="font-size:18px">{{ $P['status']['code_title'] }}</h2>
    <p>{{ $codeHelp }}</p>
    <form method="post" action="{{ $base }}/verify" novalidate style="margin-top:10px">
      @csrf
      <div class="field"><label for="st-code">{{ $P['fields']['code'] }}</label>
        <input id="st-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required></div>
      <button class="btn btn-gold" type="submit" style="margin-top:10px">{{ $P['status']['verify'] }}</button>
    </form>
    @if($canResend)
      <form method="post" action="{{ $base }}/resend" style="margin-top:10px">@csrf<button class="link" type="submit" style="background:none;border:0;padding:0;cursor:pointer">{{ $P['status']['resend'] }}</button></form>
    @endif
  </div>
@endif

@if($r->status === 'INFO_REQUESTED')
  <div class="card form-card" style="margin-top:16px">
    <h2 style="font-size:18px">{{ $P['status']['info_title'] }}</h2>
    <p style="white-space:pre-line">{{ $r->info_request }}</p>
    <form method="post" action="{{ $base }}/respond" enctype="multipart/form-data" novalidate style="margin-top:10px">
      @csrf
      <div class="field"><label for="st-response">{{ $P['fields']['response'] }}</label>
        <textarea id="st-response" name="response" required minlength="5" maxlength="5000" style="min-height:90px">{{ old('response') }}</textarea></div>
      <div class="field"><label for="st-doc">{{ $P['fields']['doc_extra'] }}</label>
        <input id="st-doc" type="file" name="doc_extra" accept=".pdf,.jpg,.jpeg,.png"></div>
      <button class="btn btn-gold" type="submit" style="margin-top:10px">{{ $P['status']['respond'] }}</button>
    </form>
  </div>
@endif

@if($r->status === 'APPROVED')<div class="note ok" style="margin-top:16px">{{ $P['status']['approved_body'] }}</div>@endif
@if($r->status === 'REJECTED' && $r->decision_note)<div class="note" style="margin-top:16px">{{ __('partner_apply.status.rejected_body', ['reason' => $r->decision_note]) }}</div>@endif

@if(! empty($r->documents))
  <div class="card" style="margin-top:16px">
    <h2 style="font-size:18px">{{ $P['status']['docs_title'] }}</h2>
    <ul style="margin:8px 0 0;padding-left:18px">
      @foreach($r->documents as $d)<li>{{ $d['name'] ?? $d['kind'] }} — {{ $P['scan'][$scans[$d['document_id']] ?? 'PENDING_SCAN'] ?? $P['scan']['PENDING_SCAN'] }}</li>@endforeach
    </ul>
  </div>
@endif
<p class="hint" style="margin-top:16px">{{ $P['status']['keep_link'] }}</p>
