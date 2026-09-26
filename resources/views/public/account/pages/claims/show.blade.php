{{-- /account/claims/<id> — design screens/compare_buy_flow/10_stage.png + 18_stage.png (customer tracking).
     Data: GET /mobile/claims/{id}, /timeline, /evidence, /evidence-requirements, /settlement; uploads via
     POST /mobile/documents + /mobile/claims/{id}/evidence; files open via POST /mobile/documents/{doc}/access.
     The customer API has no claim cancellation and no summary PDF, so the page offers print-to-PDF instead. --}}
@php $L = __('account_claims.js'); $D = $L['d']; @endphp
@extends('public.account.layout', ['title' => __('account_claims.show.title'), 'lede' => __('account_claims.show.lede'),
  'crumbs' => [[__('account_claims.list.title'), '/account/claims'], [__('account_claims.show.title'), null]], 'active' => 'claims'])
@include('public.account.claims.assets')
@section('content')
<div data-page-body><div class="acct-loading" role="status"><span class="spin"></span>{{ __('account.js.loading') }}</div></div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var K = OpesClaims, T = K.T, D = T.d, h = Opes.h, $ = Opes.$;
  var body = $('[data-page-body]'), id = ctx.ids[0];
  var loc = Opes.locale === 'fr' ? 'fr-FR' : 'en-GB';
  function time(v) { if (!v) return '—'; var d = new Date(String(v).replace(' ', 'T').replace(/([+-]\d\d)$/, '$1:00')); return isNaN(d) ? '—' : new Intl.DateTimeFormat(loc, { hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Douala' }).format(d); }
  function iso(v) { return v ? String(v).replace(' ', 'T').replace(/([+-]\d\d)$/, '$1:00') : v; }
  function rows(d) { return Array.isArray(d) ? d : (d && d.data) || []; }
  function soft(p) { return p.catch(function () { return null; }); }
  function card(title, extra) { return h('section', { class: 'acard' }, h('h2', null, title, extra || null)); }
  function dl(items) {
    return h('dl', { class: 'cl-dl' }, items.filter(function (x) { return x && x[2] !== undefined && x[2] !== null && x[2] !== ''; }).map(function (x) {
      return h('div', null, Opes.icon(x[0]), h('dt', null, x[1]), h('dd', null, x[2]));
    }));
  }

  var q = ctx.params;

  function load() {
    return Promise.all([
      Opes.api('/mobile/claims/' + encodeURIComponent(id)),
      soft(Opes.api('/mobile/claims/' + encodeURIComponent(id) + '/timeline')),
      soft(Opes.api('/mobile/claims/' + encodeURIComponent(id) + '/evidence')),
      soft(Opes.api('/mobile/claims/' + encodeURIComponent(id) + '/evidence-requirements')),
      soft(Opes.api('/mobile/claims/' + encodeURIComponent(id) + '/settlement')),
    ]).then(function (r) { render(r[0], rows(r[1]), rows(r[2]), rows(r[3]), r[4]); });
  }

  function stepper(c, events) {
    var s = K.up(c.status), cur = K.STAGE[s] !== undefined ? K.STAGE[s] : 0, declined = s === 'DECLINED', closed = s === 'CLOSED' || s === 'PAID';
    var reached = {};
    events.forEach(function (e) { var st = K.STAGE[K.up(e.to_status)]; if (st !== undefined && !reached[st]) reached[st] = e.occurred_at; });
    if (!reached[0]) reached[0] = c.submitted_at || c.created_at;
    return h('ol', { class: 'stepbar acct-steps cl-steps cl-steps6' }, K.STAGES.map(function (k, i) {
      var done = i < cur || (closed && i === cur), isCur = i === cur && !closed, bad = declined && i === cur;
      var sub = (done || isCur) && reached[i] ? Opes.date(iso(reached[i])) : (isCur ? D.in_progress : D.pending);
      if (isCur && !reached[i]) sub = D.in_progress;
      var title = bad ? K.statusLabel('DECLINED') : T.groups[k];
      return h('li', { class: (done ? 'done ' : '') + (isCur ? 'cur ' : '') + (bad ? 'bad' : '') }, h('span', { class: 'n' }, done ? Opes.icon('check') : bad ? Opes.icon('x') : String(i + 1)), h('span', null, h('b', null, title), h('small', null, sub)));
    }));
  }

  function openDoc(ev, btn) {
    Opes.busy(btn, true);
    var win = window.open('', '_blank'); // opened on the click so pop-up blockers allow it
    Opes.api('/mobile/documents/' + encodeURIComponent(ev.document_id) + '/access', { method: 'POST', body: { purpose: 'CLAIM_EVIDENCE_VIEW' } })
      .then(function (d) { var u = d && (d.url || d.download_url); if (u && win) { win.opener = null; win.location.href = u; } else { if (win) win.close(); if (!Opes.openDoc(d)) Opes.alert(Opes.t.error); } })
      .catch(function (e) { if (win) win.close(); Opes.alert(e.message); })
      .finally(function () { Opes.busy(btn, false); });
  }

  function uploader(c, type, label, onDone) {
    var inp = h('input', { type: 'file', accept: 'image/jpeg,image/png,application/pdf', hidden: true, 'data-upload': '' });
    var btn = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-upload': '', onclick: function () { inp.click(); } }, Opes.icon('download'), label);
    inp.addEventListener('change', function () {
      var f = inp.files[0]; inp.value = ''; if (!f) return;
      if (!K.fileOk(f)) { Opes.alert(K.fmt(T.wiz.err_file, { name: f.name })); return; }
      Opes.busy(btn, true);
      K.uploadEvidence(c.id, f, type).then(function () { Opes.alert(D.uploaded_ok, 'ok'); onDone(); })
        .catch(function (e) { Opes.busy(btn, false); Opes.alert(e.message); });
    });
    return [btn, inp];
  }

  function render(c, events, evidence, reqs, settle) {
    var p = c.policy || {}, r = K.risk(p), inc = (c.loss_details && c.loss_details.incident) || {}, s = K.up(c.status);
    var inspection = c.loss_details && c.loss_details.inspection, repair = c.loss_details && c.loss_details.repair;
    var at = c.incident_at || c.loss_occurred_at;
    document.title = (c.claim_number || '') + ' — OpesInsure';
    var crumb = document.querySelector('.crumbs [aria-current="page"]'); if (crumb) crumb.textContent = c.claim_number || crumb.textContent;
    Opes.clear(body);
    if (q.get('created') && !render.done) Opes.alert(q.get('failed') ? K.fmt(T.wiz.upload_failed, { n: q.get('failed') }) : K.fmt(T.wiz.done, { no: c.claim_number }), q.get('failed') ? 'bad' : 'ok');
    render.done = true;

    // Header
    body.appendChild(h('div', { class: 'cl-head' },
      h('div', null, h('h2', null, c.claim_number || '—', K.chip(c.status)), h('small', null, K.fmt(D.submitted_on, { date: Opes.date(c.submitted_at || c.created_at, true) }))),
      h('div', { class: 'btns' },
        h('button', { type: 'button', class: 'dbtn dbtn-outline', onclick: function () { window.print(); } }, Opes.icon('download'), D.print),
        h('a', { class: 'dbtn dbtn-primary', href: '/account/support?claim_id=' + encodeURIComponent(c.id) }, Opes.icon('chat'), D.contact))));
    body.appendChild(stepper(c, events));

    // Claim information
    var info = card(D.info);
    info.appendChild(dl([
      ['doc', D.no, c.claim_number], ['doc', D.policy_no, p.policy_number], ['shield', D.policy_type, K.lineLabel(p) + (K.terms(p).product ? ' — ' + K.terms(p).product : '')],
      ['business', D.insurer, K.insurer(p)], ['accident', D.type, K.typeLabel(K.incidentType(c))],
      ['clock', D.date, Opes.date(at)], ['clock', D.time, time(at)], ['pin', D.location, c.incident_location || c.loss_location],
      ['card', D.claimed, c.estimated_loss_minor !== null && c.estimated_loss_minor !== undefined ? K.amount(c.estimated_loss_minor) : null],
      ['check', D.approved, c.approved_amount_minor ? K.amount(c.approved_amount_minor) : null], ['doc', D.carrier_ref, c.carrier_reference],
      ['refresh', D.status, K.chip(c.status)],
    ]));

    // Vehicle / insured item
    var isMotor = K.line(p) === 'MOTOR';
    var veh = card(isMotor ? D.vehicle : D.risk);
    veh.appendChild(h('div', { class: 'cl-veh' }, h('span', { class: 'cl-pic' }, Opes.icon(K.lineIcon(p))), h('div', null, h('b', null, K.itemTitle(p)), h('small', null, K.itemSub(p))), h('span', { style: 'margin-left:auto' }, Opes.chip(p.status))));
    veh.appendChild(isMotor ? dl([['motor', D.make, r.make], ['motor', D.model, r.model], ['clock', D.year, r.year], ['doc', D.reg, r.registration_number], ['user', D.usage, r.usage_type ? Opes.label(r.usage_type) : null], ['target', D.power, r.fiscal_power ? r.fiscal_power + ' CV' : null]])
      : dl(Object.keys(r).filter(function (k) { return typeof r[k] !== 'object'; }).slice(0, 6).map(function (k) { return ['doc', Opes.label(k.replace(/_minor$/, '')), /_minor$/.test(k) ? K.amount(r[k]) : Opes.label(String(r[k]))]; })));

    // Description
    var desc = card(D.desc); desc.classList.add('cl-desc');
    desc.appendChild(h('p', null, c.description || (c.loss_details && c.loss_details.description) || '—'));

    // Documents
    var docs = card(D.docs); docs.appendChild(h('p', { class: 'sub' }, D.docs_d));
    var types = K.evidenceTypes(p);
    var sel = h('select', { 'aria-label': D.doc_type, 'data-upload': '', style: 'height:34px;border:1px solid #D8E1EE;border-radius:8px;font:inherit;font-size:13px;padding:0 8px;max-width:200px' }, types.map(function (k) { return h('option', { value: k }, K.evLabel(k)); }));
    var gen = h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;align-items:center' }, sel);
    var gInput = h('input', { type: 'file', accept: 'image/jpeg,image/png,application/pdf', hidden: true, 'data-upload': '' });
    var gButton = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-upload': '', onclick: function () { gInput.click(); } }, Opes.icon('download'), D.upload);
    gInput.addEventListener('change', function () {
      var f = gInput.files[0]; gInput.value = ''; if (!f) return;
      if (!K.fileOk(f)) { Opes.alert(K.fmt(T.wiz.err_file, { name: f.name })); return; }
      Opes.busy(gButton, true);
      K.uploadEvidence(c.id, f, sel.value).then(function () { Opes.alert(D.uploaded_ok, 'ok'); load(); }).catch(function (e) { Opes.busy(gButton, false); Opes.alert(e.message); });
    });
    gen.appendChild(gButton); gen.appendChild(gInput);
    docs.querySelector('h2').appendChild(gen);
    if (!evidence.length) docs.appendChild(h('p', { class: 'acct-empty', style: 'padding:14px' }, D.no_docs));
    else docs.appendChild(h('div', { class: 'cl-docs' }, evidence.map(function (ev) {
      var pdf = /pdf/.test(ev.mime_type || '');
      var b = h('button', { type: 'button', onclick: function () { openDoc(ev, b); } }, Opes.icon('eye'), D.open);
      return h('div', { class: 'cl-doc' }, h('div', { class: 'th' + (pdf ? ' pdf' : '') }, Opes.icon(pdf ? 'doc' : 'eye')),
        h('div', null, K.chip(ev.status === 'VERIFIED' ? 'APPROVED' : ev.status === 'REJECTED' ? 'DECLINED' : 'SUBMITTED').cloneNode(true)),
        h('b', null, K.evLabel(ev.evidence_type)), h('small', null, [(pdf ? 'PDF' : (ev.mime_type || '').split('/')[1] || '').toUpperCase(), K.size(ev.size_bytes), Opes.date(iso(ev.submitted_at), true)].filter(Boolean).join(' · ')), b);
    })));
    if (reqs.length) {
      docs.appendChild(h('h3', { style: 'font-size:14px;color:#0A1E4D;margin:16px 0 0' }, D.required));
      docs.appendChild(h('ul', { class: 'cl-reqs' }, reqs.map(function (rq) {
        var st = K.up(rq.status), tone = st === 'VERIFIED' || st === 'UPLOADED' ? 'ok' : st === 'REJECTED' ? 'bad' : 'warn';
        var lbl = { MISSING: D.missing, UPLOADED: D.uploaded, VERIFIED: D.verified, REJECTED: D.rejected }[st] || st;
        var right = st === 'MISSING' || st === 'REJECTED' ? uploader(c, rq.key, D.upload, load) : null;
        return h('li', null, h('div', null, h('b', null, K.evLabel(rq.key) !== Opes.label(rq.key) ? K.evLabel(rq.key) : rq.label, rq.required ? h('small', null, ' · ' + D.mandatory) : null), h('small', null, rq.guidance || '')),
          h('span', { class: 'st st-' + tone }, lbl), right || h('span'));
      })));
    }

    // Side: progress timeline
    var prog = card(D.progress);
    var tl = h('ol', { class: 'timeline', style: 'margin-top:10px' });
    if (!events.length) tl.appendChild(h('li', null, h('small', null, D.no_events)));
    events.forEach(function (e, i) {
      var key = K.up(e.type), bad = K.up(e.to_status) === 'DECLINED';
      tl.appendChild(h('li', { class: bad ? 'bad' : (i === events.length - 1 && s !== 'CLOSED' ? 'cur' : 'done') }, h('span', { class: 'dotc' }),
        h('b', null, T.events[key] || T.events[K.up(e.to_status)] || K.statusLabel(e.to_status)), h('small', null, Opes.date(iso(e.occurred_at), true))));
    });
    // Remaining stages, pending.
    var cur = K.STAGE[s] || 0;
    if (s !== 'DECLINED') K.STAGES.slice(cur + 1).forEach(function (k) { tl.appendChild(h('li', null, h('span', { class: 'dotc' }), h('b', null, T.groups[k]), h('small', null, D.pending))); });
    prog.appendChild(tl);

    var side = [prog];
    if (inspection && (inspection.surveyor_name || inspection.appointment_at)) {
      var o = card(D.officer);
      o.appendChild(dl([['user', D.surveyor, inspection.surveyor_name], ['clock', D.appointment, inspection.appointment_at ? Opes.date(inspection.appointment_at, true) : null], ['pin', D.location, inspection.location],
        ['phone', D.phone, inspection.contact_phone ? h('a', { href: 'tel:' + inspection.contact_phone }, inspection.contact_phone) : null], ['help', '', inspection.notes]]));
      side.push(o);
    }
    if (settle && settle.status && (settle.offered_minor > 0 || K.up(settle.status) !== 'PENDING_DECISION')) {
      var st2 = card(D.settlement, Opes.chip(settle.status));
      st2.appendChild(dl([['card', D.offered, K.amount(settle.offered_minor)], ['card', D.deductible, K.amount(settle.deductible_minor)], ['check', D.net, K.amount(settle.net_minor)],
        ['refresh', D.payment, settle.payment_status ? Opes.chip(settle.payment_status) : null], ['doc', D.pay_ref, settle.payment_reference]]));
      if (settle.terms) st2.appendChild(h('p', { class: 'sub', style: 'margin:10px 0 0' }, settle.terms));
      side.push(st2);
    }
    if (repair && (repair.garage_name || repair.estimate_minor)) {
      var rp = card(D.repair, repair.status ? Opes.chip(repair.status) : null);
      rp.appendChild(dl([['business', D.garage, repair.garage_name], ['card', D.estimate, repair.estimate_minor ? K.amount(repair.estimate_minor) : null]]));
      side.push(rp);
    }
    var nk = s === 'DECLINED' ? 'declined' : K.STAGES[cur];
    var nx = h('section', { class: 'acard cl-info cl-next' }, h('h2', null, h('span', { style: 'display:inline-flex;gap:8px;align-items:center' }, Opes.icon('help'), D.next_t)), h('p', { style: 'margin:6px 0 0;font-size:13.5px' }, D.next[nk]));
    side.push(nx);

    var grid = h('div', { class: 'agrid main-side' },
      h('div', { class: 'cl-stack' }, h('div', { class: 'agrid c2' }, info, veh), desc, docs),
      h('div', { class: 'cl-stack side' }, side));
    body.appendChild(grid);
    body.appendChild(h('div', { class: 'cl-bar' }, h('div', { class: 'note' }, Opes.icon('help'), h('div', null, h('b', null, D.important), D.important_d)),
      h('a', { class: 'dbtn dbtn-outline', href: '/account/claims' }, Opes.icon('chev-left'), T.stats.all_claims)));
  }

  return load().catch(function (e) { if (e && (e.status === 404 || e.status === 403)) return Opes.empty(body, D.not_found, h('a', { class: 'dbtn dbtn-primary sm', href: '/account/claims' }, T.stats.all_claims)); Opes.fail(body, e); });
});
</script>
@endpush
