{{-- /account/claims/new[?policy=<id>] — design screens/compare_buy_flow/09_stage.png + 17_stage.png.
     Same flow as the mobile app: GET /mobile/wallet (policies), POST /mobile/claims (FNOL, form claim_fnol),
     then each file via POST /mobile/documents + POST /mobile/claims/{id}/evidence. The API has no claim drafts. --}}
@php $L = __('account_claims.js'); $W = $L['wiz']; @endphp
@extends('public.account.layout', ['title' => __('account_claims.new.title'), 'lede' => __('account_claims.new.lede'),
  'crumbs' => [[__('account_claims.list.title'), '/account/claims'], [__('account_claims.new.title'), null]], 'active' => 'claims'])
@include('public.account.claims.assets')
@section('content')
<div data-stepper></div>
<div class="agrid main-side cl-main">
  <form class="cl-form" data-form novalidate>
    <div data-edit>
      <section class="acard cl-sec" data-sec="1">
        <h2>{{ $W['s1'] }} <a class="cl-link" href="/account/policies">{{ $W['all_policies'] }} @include('public.partials.i', ['n' => 'arrow'])</a></h2>
        <p class="sub">{{ $W['s1d'] }}</p>
        <div data-policies><div class="acct-loading" role="status"><span class="spin"></span>{{ __('account.js.loading') }}</div></div>
      </section>

      <section class="acard cl-sec" data-sec="2" style="margin-top:16px">
        <h2>{{ $W['s2'] }}</h2>
        <p class="sub">{{ $W['s2d'] }}</p>
        <div class="cl-row">
          <fieldset class="afield-s" style="border:0;padding:0;margin:0;min-width:0"><legend style="font-weight:600;color:#1C2B45;margin-bottom:6px">{{ $W['type'] }} <i>*</i></legend><div class="cl-types" data-types></div></fieldset>
          <label class="afield-s"><span>{{ $W['date'] }} <i>*</i></span><input type="date" name="date" required></label>
          <label class="afield-s"><span>{{ $W['time'] }} <i>*</i></span><input type="time" name="time" required></label>
        </div>
        <div class="cl-row2" style="margin-top:14px">
          <label class="afield-s"><span>{{ $W['location'] }} <i>*</i></span><input type="text" name="location" maxlength="120" placeholder="{{ $W['location_ph'] }}" required></label>
          <label class="afield-s"><span>{{ $W['address'] }}</span><input type="text" name="address" maxlength="130" placeholder="{{ $W['address_ph'] }}"></label>
        </div>
        <label class="afield-s" style="margin-top:14px"><span>{{ $W['desc'] }} <i>*</i></span><textarea name="description" maxlength="1000" minlength="10" placeholder="{{ $W['desc_ph'] }}" required></textarea><small class="cl-counter" data-counter>0/1000</small></label>
        <div class="cl-row2" style="margin-top:6px">
          <label class="afield-s"><span>{{ $W['estimate'] }}</span><input type="number" name="estimate" min="0" step="1000" inputmode="numeric" placeholder="{{ $W['estimate_ph'] }}"></label>
          <label class="afield-s" data-police-ref hidden><span>{{ $W['police_ref'] }}</span><input type="text" name="police_reference" maxlength="120"></label>
        </div>
        <div class="cl-checks" style="margin-top:12px">
          <label><input type="checkbox" name="injuries">{{ $W['injuries'] }}</label>
          <label><input type="checkbox" name="police">{{ $W['police'] }}</label>
        </div>
      </section>

      <section class="acard cl-sec" data-sec="3" style="margin-top:16px">
        <h2>{{ $W['s3'] }}</h2>
        <p class="sub">{{ $W['s3d'] }}</p>
        <div class="cl-up">
          <label class="drop" data-drop>
            @include('public.partials.i', ['n' => 'download'])
            <span>{{ $W['drop'] }} <b style="color:var(--royal)">{{ $W['browse'] }}</b></span>
            <small>{{ $W['formats'] }}</small>
            <input type="file" data-file-any accept="image/jpeg,image/png,application/pdf" multiple hidden>
          </label>
          <div class="cl-req"><b>{{ $W['req_t'] }}</b> <small>{{ $W['req_d'] }}</small><ul data-req></ul></div>
        </div>
        <div class="cl-slots" data-slots></div>
        <div class="cl-files" data-files></div>
      </section>
    </div>

    <section class="acard cl-sec" data-review hidden>
      <h2>{{ $W['s4'] }}</h2>
      <p class="sub">{{ $W['s4d'] }}</p>
      <div class="cl-review" data-review-body></div>
      <label class="cl-declare"><input type="checkbox" name="declare">{{ $W['declare'] }}</label>
    </section>

    <div class="cl-actions">
      <a class="dbtn dbtn-outline" href="/account/claims" data-back>@include('public.partials.i', ['n' => 'chev-left']){{ $W['back'] }}</a>
      <button type="submit" class="dbtn dbtn-primary" data-next>{{ $W['review'] }}@include('public.partials.i', ['n' => 'arrow'])</button>
    </div>
  </form>

  <aside class="cl-side">
    <section class="acard cl-polcard" data-polcard hidden></section>
    <section class="acard cl-info">
      <h2><span style="display:inline-flex;gap:8px;align-items:center">@include('public.partials.i', ['n' => 'help']){{ $W['info_t'] }}</span></h2>
      <ul>@foreach($W['info'] as $line)<li>{{ $line }}</li>@endforeach</ul>
    </section>
    <section class="acard cl-help">
      <div class="cl-help-h"><span class="cl-hic">@include('public.partials.i', ['n' => 'headset'])</span><div><b>{{ $L['help']['t'] }}</b><small>{{ $L['help']['d'] }}</small></div></div>
      <div class="cl-2btn">
        <a class="dbtn dbtn-outline sm" href="/contact?topic=claims">@include('public.partials.i', ['n' => 'phone']){{ $L['help']['call'] }}</a>
        <a class="dbtn dbtn-outline sm" href="/account/support">@include('public.partials.i', ['n' => 'chat']){{ $L['help']['chat'] }}</a>
      </div>
    </section>
    <section class="acard">
      <h2 class="cl-guide-h"><span>@include('public.partials.i', ['n' => 'doc']){{ $L['guide']['t'] }}</span></h2>
      <ul class="cl-guide">@foreach($L['guide']['links'] as [$gl, $gh])<li><a href="{{ $gh }}">@include('public.partials.i', ['n' => 'doc'])<span>{{ $gl }}</span>@include('public.partials.i', ['n' => 'chev'])</a></li>@endforeach</ul>
    </section>
  </aside>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var K = OpesClaims, T = K.T, W = T.wiz, h = Opes.h, $ = Opes.$, $$ = Opes.$$;
  var form = $('[data-form]'), el = form.elements;
  var policies = [], policy = null, files = [], reviewing = false, idemKey = Opes.uuid(), created = null;
  var loc = Opes.locale === 'fr' ? 'fr-FR' : 'en-GB';

  // Today in Africa/Douala (UTC+1, no DST) as the max incident date.
  var now = new Date(Date.now() + 3600e3), today = now.toISOString().slice(0, 10);
  el.date.max = today;

  function step() {
    if (reviewing) return 3;
    if (!policy) return 0;
    if (!type() || !el.date.value || !el.time.value || !el.location.value.trim() || el.description.value.trim().length < 10) return 1;
    return 2;
  }
  function paintStepper() { var s = $('[data-stepper]'); Opes.clear(s).appendChild(Opes.stepper(W.steps, step())); s.firstChild.classList.add('cl-steps'); }
  function type() { var c = form.querySelector('input[name="itype"]:checked'); return c ? c.value : ''; }

  // ---- policies (only ACTIVE can be claimed against) ----
  function polRow(p) {
    var t = K.terms(p);
    return h('label', { class: 'cl-pol' },
      h('input', { type: 'radio', name: 'policy', value: p.id, onchange: function () { choose(p); } }),
      h('span', { class: 'pic' }, Opes.icon(K.lineIcon(p))),
      h('div', null, h('b', null, (K.lineLabel(p) || p.policy_number) + (t.product && t.product !== K.lineLabel(p) ? ' — ' + t.product : '')), h('small', null, [K.itemTitle(p), K.risk(p).registration_number].filter(Boolean).join(' | ')), h('small', null, W.policy_no + ': ' + p.policy_number), h('div', { style: 'margin-top:4px' }, Opes.chip(p.status))),
      h('div', { class: 'cell' }, h('small', null, W.start), h('b', null, Opes.date(p.coverage_starts_at))),
      h('div', { class: 'cell' }, h('small', null, W.end), h('b', null, Opes.date(p.coverage_ends_at))),
      h('div', { class: 'cell x' }, h('small', null, W.insurer), h('b', null, K.insurer(p) || '—')));
  }
  function choose(p) {
    policy = p;
    var r = K.risk(p), card = $('[data-polcard]'), t = K.terms(p);
    Opes.clear(card).appendChild(h('div', { class: 'hdr' }, h('h2', { style: 'margin:0;font-size:17px;color:#0A1E4D' }, W.your_policy), Opes.chip(p.status)));
    card.appendChild(h('div', { class: 'who' }, h('span', { class: 'cl-pic' }, Opes.icon(K.lineIcon(p))), h('div', null, h('b', null, K.lineLabel(p)), h('small', null, K.itemTitle(p) + (r.registration_number ? ' · ' + r.registration_number : '')), h('small', null, W.policy_no + ': ' + p.policy_number))));
    var rows = [['clock', W.period, Opes.date(p.coverage_starts_at) + ' – ' + Opes.date(p.coverage_ends_at)], ['shield', W.insurer, K.insurer(p) || '—'], ['doc', W.product, t.product || '—'], ['card', W.premium, K.amount(t.total_minor || p.premium_minor)]];
    card.appendChild(h('dl', { class: 'cl-kv' }, rows.map(function (x) { return h('div', null, Opes.icon(x[0]), h('dt', null, x[1]), h('dd', null, x[2])); })));
    card.hidden = false;
    renderSlots(); paintStepper();
  }

  // ---- incident types: master list claims.claim_category when the API has one, else the built-in set ----
  function typeCards(list) {
    var box = Opes.clear($('[data-types]'));
    list.forEach(function (t) {
      box.appendChild(h('label', { class: 'cl-tc' }, h('input', { type: 'radio', name: 'itype', value: t.code, onchange: paintStepper }), Opes.icon(K.typeIcon(t.code)), t.label));
    });
  }
  var builtIn = (T.type_cards || []).map(function (c) { return { code: c, label: K.typeLabel(c) }; });
  typeCards(builtIn);
  Opes.api('/master-data/claims').then(function (d) {
    var l = d && d.lists && d.lists.filter(function (x) { return x.code === 'claim_category'; })[0];
    if (l && l.values && l.values.length) typeCards(l.values.map(function (v) { return { code: v.code, label: (v.label && (v.label[Opes.locale] || v.label.en)) || v.code }; }));
  }).catch(function () { /* no master list on this server: keep the built-in types */ });

  // ---- files ----
  function renderSlots() {
    var types = K.evidenceTypes(policy), slots = Opes.clear($('[data-slots]')), req = Opes.clear($('[data-req]'));
    types.forEach(function (k) { req.appendChild(h('li', null, Opes.icon('doc'), K.evLabel(k))); });
    types.slice(0, 3).concat(['OTHER']).forEach(function (k) {
      var inp = h('input', { type: 'file', accept: 'image/jpeg,image/png,application/pdf', multiple: true, hidden: true, onchange: function () { add(inp.files, k); inp.value = ''; } });
      slots.appendChild(h('div', { class: 'cl-slot' }, h('div', { class: 'hd' }, Opes.icon(k === 'DAMAGE_PHOTO' ? 'eye' : 'doc'), h('div', null, h('b', null, K.evLabel(k)), h('small', null, W.slot_hint))),
        h('button', { type: 'button', class: 'dbtn dbtn-outline', onclick: function () { inp.click(); } }, W.choose), inp));
    });
    files.forEach(function (f) { if (types.indexOf(f.type) < 0) f.type = 'OTHER'; });
    renderFiles();
  }
  function add(list, t) {
    Array.prototype.forEach.call(list || [], function (f) {
      if (!K.fileOk(f)) { Opes.alert(K.fmt(W.err_file, { name: f.name })); return; }
      files.push({ file: f, type: t || K.evidenceTypes(policy)[0], url: f.type.indexOf('image/') === 0 ? URL.createObjectURL(f) : null });
    });
    renderFiles();
  }
  function renderFiles() {
    var box = Opes.clear($('[data-files]')), types = K.evidenceTypes(policy);
    files.forEach(function (x, i) {
      var sel = h('select', { 'aria-label': T.d.doc_type, onchange: function () { x.type = sel.value; } }, types.map(function (k) { return h('option', { value: k, selected: k === x.type ? true : null }, K.evLabel(k)); }));
      box.appendChild(h('div', { class: 'cl-file' },
        h('div', { class: 'th' }, x.url ? h('img', { src: x.url, alt: '' }) : Opes.icon('doc')),
        h('button', { type: 'button', class: 'rm', 'aria-label': W.remove + ' ' + x.file.name, onclick: function () { if (x.url) URL.revokeObjectURL(x.url); files.splice(i, 1); renderFiles(); } }, Opes.icon('x')),
        h('b', { title: x.file.name }, x.file.name), h('small', null, K.size(x.file.size)), sel));
    });
  }
  var drop = $('[data-drop]'), anyInput = $('[data-file-any]');
  anyInput.addEventListener('change', function () { add(anyInput.files); anyInput.value = ''; });
  ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) { add(e.dataTransfer && e.dataTransfer.files); });

  // ---- form helpers ----
  el.description.addEventListener('input', function () { $('[data-counter]').textContent = el.description.value.length + '/1000'; paintStepper(); });
  el.police.addEventListener('change', function () { $('[data-police-ref]').hidden = !el.police.checked; });
  ['date', 'time', 'location'].forEach(function (n) { el[n].addEventListener('input', paintStepper); });

  function incidentAt() { return el.date.value && el.time.value ? el.date.value + 'T' + el.time.value + ':00+01:00' : ''; }
  function locationText() { return [el.location.value.trim(), el.address.value.trim()].filter(Boolean).join(', ').slice(0, 255); }
  function validate() {
    if (!policy) return W.err_policy;
    if (!type()) return W.err_type;
    var at = incidentAt(); if (!at || new Date(at) > new Date()) return W.err_date;
    if ((policy.coverage_starts_at && new Date(at) < new Date(policy.coverage_starts_at)) || (policy.coverage_ends_at && new Date(at) > new Date(policy.coverage_ends_at))) return W.err_cover;
    if (!el.location.value.trim()) return W.err_location;
    if (el.description.value.trim().length < 10) return W.err_desc;
    return '';
  }
  function typeText() { var c = form.querySelector('input[name="itype"]:checked'); return c ? c.parentNode.textContent : ''; }
  function review() {
    var dt = new Date(incidentAt());
    var left = [[W.policy_no, policy.policy_number], [W.product, K.terms(policy).product || K.lineLabel(policy)], [W.vehicle, K.itemTitle(policy) + (K.risk(policy).registration_number ? ' (' + K.risk(policy).registration_number + ')' : '')], [W.insurer, K.insurer(policy) || '—']];
    var right = [[W.type, typeText()], [W.date, new Intl.DateTimeFormat(loc, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Douala' }).format(dt)], [W.location, locationText()],
      [W.estimate, el.estimate.value ? Opes.money(Number(el.estimate.value)) : '—']];
    function kv(rows) { return h('dl', { class: 'kv' }, rows.map(function (r) { return [h('dt', null, r[0]), h('dd', null, r[1])]; })); }
    var b = Opes.clear($('[data-review-body]'));
    b.appendChild(kv(left)); b.appendChild(kv(right));
    b.appendChild(h('div', { style: 'grid-column:1/-1' }, h('b', { style: 'color:#0A1E4D' }, W.desc), h('p', { style: 'margin:4px 0 0;white-space:pre-line;font-size:14px' }, el.description.value.trim())));
    b.appendChild(h('div', { style: 'grid-column:1/-1' }, h('b', { style: 'color:#0A1E4D' }, W.files + ' (' + files.length + ')'),
      files.length ? h('ul', { style: 'margin:4px 0 0;padding-left:18px;font-size:13.5px' }, files.map(function (x) { return h('li', null, x.file.name + ' — ' + K.evLabel(x.type)); })) : h('p', { class: 'sub', style: 'margin:4px 0 0' }, W.no_files)));
  }
  function setMode(r) {
    reviewing = r;
    $('[data-edit]').hidden = r; $('[data-review]').hidden = !r;
    var next = $('[data-next]'), back = $('[data-back]');
    next.innerHTML = ''; next.appendChild(document.createTextNode(r ? W.submit : W.review)); next.appendChild(Opes.icon('arrow'));
    back.setAttribute('href', r ? '#' : '/account/claims');
    paintStepper(); window.scrollTo({ top: $('[data-stepper]').getBoundingClientRect().top + window.scrollY - 90, behavior: 'smooth' });
  }
  $('[data-back]').addEventListener('click', function (e) { if (reviewing) { e.preventDefault(); setMode(false); } });

  function submit(btn) {
    btn.disabled = true;
    Opes.alert(W.submitting, 'info');
    var body = { policy_id: policy.id, incident_at: incidentAt(), incident_location: locationText(), description: el.description.value.trim(), incident_type: type(),
      injuries_reported: el.injuries.checked, police_report_filed: el.police.checked };
    if (el.police.checked && el.police_reference.value.trim()) body.police_reference = el.police_reference.value.trim();
    if (el.estimate.value) body.estimated_loss_minor = Math.round(Number(el.estimate.value) * 100);
    var create = created ? Promise.resolve(created) : Opes.api('/mobile/claims', { method: 'POST', body: body, idemKey: idemKey });
    create.then(function (c) {
      created = c;
      var failed = 0, i = 0;
      function nextFile() {
        if (i >= files.length) return Promise.resolve();
        var x = files[i++];
        Opes.alert(K.fmt(W.uploading, { i: i, n: files.length }), 'info');
        return K.uploadEvidence(c.id, x.file, x.type).catch(function () { failed++; }).then(nextFile);
      }
      return nextFile().then(function () {
        var q = '?created=1' + (failed ? '&failed=' + failed : '');
        location.href = '/account/claims/' + encodeURIComponent(c.id) + q;
      });
    }).catch(function (e) { btn.disabled = false; Opes.alert((e && e.message) || Opes.t.error); });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault(); Opes.alert('');
    var err = validate(); if (err) { Opes.alert(err); return; }
    if (!reviewing) { review(); setMode(true); return; }
    if (!el.declare.checked) { Opes.alert(W.err_declare); return; }
    submit($('[data-next]'));
  });

  paintStepper(); renderSlots();
  var box = $('[data-policies]');
  return Opes.api('/mobile/wallet').then(function (d) {
    var all = Array.isArray(d) ? d : (d && d.data) || [];
    policies = all.filter(function (p) { return K.up(p.status) === 'ACTIVE'; });
    if (!policies.length) {
      $$('[data-sec="2"],[data-sec="3"]').forEach(function (s) { s.setAttribute('aria-disabled', 'true'); });
      $('[data-next]').disabled = true;
      return Opes.empty(box, W.no_policies, h('a', { class: 'dbtn dbtn-primary sm', href: '/insurance' }, W.get_cover));
    }
    Opes.clear(box); policies.forEach(function (p) { box.appendChild(polRow(p)); });
    var want = ctx.params.get('policy') || (policies.length === 1 ? policies[0].id : '');
    var pick = policies.filter(function (p) { return p.id === want; })[0];
    if (pick) { box.querySelector('input[value="' + pick.id + '"]').checked = true; choose(pick); }
  }).catch(function (e) { Opes.fail(box, e); });
});
</script>
@endpush
