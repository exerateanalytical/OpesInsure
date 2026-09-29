{{-- /account/agent/proposals/{id} — AGT-031 Proposal Review + AGT-032 Underwriting Status + AGT-034 Conditional Offer.
     GET /proposals/{id} (underwriting case + decisions) and GET /proposals/{id}/checklist — both book-scoped: another agent's client
     answers 403. Actions: POST /proposals/{id}/submit | withdraw (when the state machine allows them); the counter-offer is shown to the
     agent, only the client can accept it (POST /mobile/proposals/{id}/counteroffer/{answer} from their own session). --}}
@php $K = __('launch_agent_a'); @endphp
@extends('public.account.layout', ['title' => $K['proposal_t'], 'lede' => $K['proposal_lede'], 'crumbs' => [[$K['dash_t'], '/account/agent'], [$K['proposal_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.launch-a')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
  <section class="acard" data-counter hidden></section>
  <div class="desk-main">
    <div><section class="acard" data-uw></section><section class="acard" data-check></section></div>
    <aside class="desk-side"><section class="acard" data-acts></section></aside>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, L = AgentA, O = Opes, h = O.h, $ = O.$;
  if (!A.guard(ctx)) return;
  var id = ctx.ids[0], e = encodeURIComponent(id), box = $('[data-page-body]'), uw = $('[data-uw]'), ck = $('[data-check]'), acts = $('[data-acts]'), co = $('[data-counter]');
  var STEPS = ['DRAFT', 'SUBMITTED', 'REVIEWING', 'INFORMATION_REQUIRED', 'COUNTEROFFERED', 'APPROVED'];
  load();
  function load() {
    [box, uw, ck].forEach(O.loading); O.clear(acts);
    return Promise.all([O.api('/proposals/' + e), O.api('/proposals/' + e + '/checklist'), L.soft(L.proposalRow(id))]).then(function (r) { paint(r[0], r[1], r[2] || {}); })
      .catch(function (err) { A.fail(box, err); O.clear(uw); O.clear(ck); });
  }
  function paint(p, c, row) {
    var st = String(p.status).toUpperCase(), bp = String(p.blueprint_state || st).toUpperCase(), t = p.terms_snapshot || p.offer || {};
    O.clear(box).append(h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('div', null, h('h2', null, L.t('pr_number') + ' ' + (p.proposal_number || '')),
      h('small', null, [row.customer_name, (row.carrier_short_name || row.carrier_name), A.line(row.line_code)].filter(Boolean).join(' · ')))), h('div', { class: 'btns' }, O.chip(st, A.label(st)))),
      h('div', { class: 'ag-fields' }, A.field(L.t('premium'), A.money(t.total_minor)), A.field(L.t('created'), O.date(p.created_at)), A.field(A.t('th_status'), A.label(st)),
        A.field(L.t('client'), row.customer_id ? h('a', { href: '/account/customers/' + encodeURIComponent(row.customer_id) }, row.customer_name || '—') : row.customer_name)));

    // AGT-032: where the proposal is in underwriting.
    var cur = Math.max(0, STEPS.indexOf(bp === 'RESUBMITTED' ? 'REVIEWING' : bp === 'PAYMENT_PENDING' ? 'APPROVED' : bp));
    var uc = p.underwriting_case || {}, decs = (uc.decisions || []).slice().sort(function (a, b) { return String(b.decided_at || b.created_at).localeCompare(String(a.decided_at || a.created_at)); });
    O.clear(uw).append(h('h2', null, L.t('pr_uw')), O.stepper(STEPS.map(function (s) { return [(L.T.pr_steps || {})[s] || s]; }), cur),
      h('div', { class: 'ag-fields' }, A.field(L.t('pr_case'), uc.status ? O.chip(uc.status, A.label(uc.status)) : null), A.field(L.t('a_due'), O.date(uc.decision_due_at))));
    if (decs.length) uw.appendChild(h('div', null, h('h3', null, L.t('pr_decisions')), L.table(['date', 'status', 'reason'], decs.map(function (d) {
      return h('tr', null, h('td', null, O.date(d.decided_at || d.created_at, true)), h('td', null, O.chip(d.decision || d.outcome, A.label(d.decision || d.outcome))), h('td', null, [d.reason_code ? O.label(d.reason_code) : null, d.notes].filter(Boolean).join(' — ') || '—'));
    }))));

    // AGT-034: conditional offer.
    co.hidden = st !== 'COUNTEROFFERED';
    if (st === 'COUNTEROFFERED') {
      var cd = decs.filter(function (d) { return String(d.decision || d.outcome).toUpperCase().indexOf('COUNTER') >= 0; })[0] || decs[0] || {}, cond = cd.conditions || {};
      var money = Object.keys(cond).filter(function (k) { return /_minor$/.test(k); }), other = Object.keys(cond).filter(function (k) { return !/_minor$/.test(k); });
      O.clear(co).append(h('h2', null, L.t('pr_counter')), h('p', { class: 'sub' }, L.t('pr_counter_d')),
        h('div', { class: 'ag-fields', 'data-counter-terms': '' }, A.field(L.t('premium'), A.money(t.total_minor)), money.map(function (k) { return A.field(O.label(k.replace(/^revised_/, '').replace(/_minor$/, '')), A.money(cond[k])); })),
        other.length ? h('div', null, h('h3', null, L.t('pr_conditions')), h('ul', null, other.map(function (k) { var v = cond[k]; return h('li', null, O.label(k) + ': ' + (typeof v === 'object' ? JSON.stringify(v) : String(v))); }))) : null,
        cd.notes ? h('p', null, cd.notes) : null);
    }

    // AGT-031: completeness.
    var qs = c.questions || [], ans = c.answers || {}, answered = qs.filter(function (q) { return ans[q.code] !== undefined && ans[q.code] !== ''; }).length;
    var docs = c.required_documents || [], decl = c.declarations || [];
    O.clear(ck).append(h('h2', null, L.t('pr_checklist')), h('div', { class: 'ag-fields' },
      A.field(L.t('pr_questions'), answered + ' / ' + qs.length), A.field(L.t('pr_attested'), c.attested_at ? O.date(c.attested_at, true) : L.t('pr_no')),
      A.field(L.t('pr_decl'), decl.filter(function (d) { return d.accepted; }).length + ' / ' + decl.length), A.field(L.t('pr_docs'), docs.filter(function (d) { return ['UPLOADED', 'REVIEWING', 'ACCEPTED'].indexOf(d.status) >= 0; }).length + ' / ' + docs.length)));
    if ((c.blocking || []).length && ['DRAFT', 'DISCLOSURES_PENDING', 'DOCUMENTS_PENDING'].indexOf(st) >= 0) ck.appendChild(h('div', { class: 'bnote' }, h('b', null, L.t('pr_blockers')), h('ul', null, c.blocking.map(function (b) { var s = b.split(':'); return h('li', null, O.label(s[0]) + (s[1] ? ' — ' + O.label(s[1]) : '')); }))));

    // Actions
    var ev = c.available_transitions || p.available_transitions || [], quoteId = (p.offer || {}).quote_id;
    acts.appendChild(h('h2', null, L.t('actions')));
    var bx = h('div', { style: 'display:flex;flex-direction:column;gap:8px' });
    if (quoteId && ['DRAFT', 'DISCLOSURES_PENDING', 'DOCUMENTS_PENDING'].indexOf(st) >= 0) bx.appendChild(L.link(L.t('pr_open_builder'), '/account/quotes/' + encodeURIComponent(quoteId) + '/review?offer=' + encodeURIComponent(p.quote_offer_id) + '&proposal=' + e, 'dbtn-primary', 'edit'));
    bx.appendChild(L.link(L.t('pr_manage_docs'), '/account/agent/proposals/' + e + '/documents', null, 'doc'));
    if (st === 'INFORMATION_REQUIRED') bx.appendChild(L.link(L.t('pr_answer_info'), '/account/agent/proposals/' + e + '/information', 'dbtn-primary', 'help'));
    if (['APPROVED', 'PAYMENT_PENDING'].indexOf(st) >= 0) bx.appendChild(L.link(L.t('pr_pay'), '/account/book/payments', 'dbtn-primary', 'card'));
    if (p.policy_id) bx.appendChild(L.link(L.t('pr_policy'), '/account/book/policies/' + encodeURIComponent(p.policy_id), null, 'shield'));
    if (ev.indexOf('submit') >= 0 && !(c.blocking || []).length) bx.appendChild(h('button', { type: 'button', class: 'dbtn dbtn-primary sm', onclick: function () { act(this, 'submit', {}, 'pr_submitted'); } }, L.t('pr_submit')));
    if (ev.indexOf('withdraw') >= 0) bx.appendChild(h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { var why = window.prompt(L.t('pr_withdraw_q')); if (why === null) return; act(this, 'withdraw', why ? { reason: why } : {}, 'pr_withdrawn'); } }, L.t('pr_withdraw')));
    acts.appendChild(bx);
  }
  function act(btn, path, body, ok) {
    O.busy(btn, true); O.alert('');
    O.api('/proposals/' + e + '/' + path, { body: body }).then(function () { O.alert(L.t(ok), 'ok'); load(); }).catch(function (err) { O.busy(btn, false); O.alert(A.errMsg(err), 'bad'); });
  }
});
</script>
@endpush
